from fastapi import FastAPI, UploadFile, File, HTTPException, Request
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel
import insightface
from insightface.app import FaceAnalysis
import numpy as np
import cv2
import uvicorn
import faiss
import argparse
import mysql.connector
from datetime import datetime, timedelta
from typing import Optional, List

# Command-line arguments
parser = argparse.ArgumentParser()
parser.add_argument("--db-host", default="localhost")
parser.add_argument("--db-user", default="root")
parser.add_argument("--db-pass", default="")
parser.add_argument("--port", default=8001, type=int)
args, unknown = parser.parse_known_args()

# Initialise FastAPI app
app = FastAPI()

# Allow cross-origin requests if needed (optional)
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# Initialise InsightFace (CPU-only)
face_app = FaceAnalysis(name='buffalo_l')
face_app.prepare(ctx_id=-1)  # Use CPU

# Dictionary to hold FAISS index and metadata per (database, scope)
db_indexes = {}


def cache_key(db_name, collection, untagged):
    """Cache key must fully capture the scope of the request, otherwise a
    request for one scope can silently reuse another scope's cached index.

    collection and untagged are independent, stackable filters now (not
    mutually exclusive), so the key needs both dimensions:
      - collection: <id> or 'all'
      - untagged:   'untagged' (node IS NULL) or 'tagged' (no node filter)
    e.g. db1::5::untagged, db1::all::untagged, db1::5::tagged
    """
    return f"{db_name}::{collection or 'all'}::{'untagged' if untagged else 'tagged'}"


def build_face_query(untagged, collection):
    """Build the WHERE clause + params shared by load_vectors and the
    freshness checks in ensure_index_loaded, so the two can never drift
    out of sync with each other.

    Returns (where_sql, params) where where_sql is either '' or a string
    starting with ' WHERE ...'.
    """
    conditions = []
    params = []

    if collection:
        conditions.append("""resource IN (
            SELECT resource FROM collection_resource WHERE collection = %s
        )""")
        params.append(collection)

    if untagged:
        conditions.append("node IS NULL")

    where_sql = f" WHERE {' AND '.join(conditions)}" if conditions else ""
    return where_sql, params


# DB connection helper
def get_mysql_connection(db_name):
    return mysql.connector.connect(
        host=args.db_host,
        database=db_name,
        user=args.db_user,
        password=args.db_pass
    )

# Load vectors from MySQL for a given database (+ optional collection, or
# the untagged-only scope)
def load_vectors(db_name, collection, untagged=False):
    where_sql, params = build_face_query(untagged, collection)

    conn = get_mysql_connection(db_name)
    cursor = conn.cursor()
    cursor.execute(
        f"SELECT ref, resource, vector_blob, node FROM resource_face{where_sql}",
        params
    )
    results = cursor.fetchall()
    conn.close()

    if not results:
        scope_desc = f"collection {collection}" if collection else "all"
        if untagged:
            scope_desc += " (untagged only)"
        print(f"No face vectors found for '{db_name}' / {scope_desc}.")
        return

    vectors = []
    index_to_metadata = []
    max_ref = 0

    for ref, resource, blob, node in results:
        vector = np.frombuffer(blob, dtype=np.float32)
        vector = vector / np.linalg.norm(vector)
        vectors.append(vector)
        index_to_metadata.append({"ref": ref, "resource": resource, "node": node})
        max_ref = max(max_ref, ref)

    row_count = len(vectors)

    d = len(vectors[0])
    index = faiss.IndexFlatIP(d)
    index.add(np.array(vectors).astype('float32'))

    key = cache_key(db_name, collection, untagged)
    db_indexes[key] = {
        "index": index,
        "metadata": index_to_metadata,
        "vectors": np.array(vectors).astype('float32'),
        "last_used": datetime.utcnow(),
        "max_ref": max_ref,
        "row_count": row_count,
        "collection": collection,
        "untagged": untagged,
    }
    print(f"Loaded {row_count} vectors for key '{key}'.")

# Request model for similarity search
class FaceSearchRequest(BaseModel):
    ref: int
    db: str
    threshold: float = 0.0
    k: int = 10
    collection: Optional[int] = None
    untagged: bool = False

# Request model for bulk similarity search — same as above but takes a list
# of refs and returns matches for all of them from a single FAISS call.
class FaceBulkSearchRequest(BaseModel):
    refs: List[int]
    db: str
    threshold: float = 0.0
    k: int = 10
    collection: Optional[int] = None
    untagged: bool = False

def ensure_index_loaded(db_name, collection, untagged=False):
    """Make sure the FAISS index for (db_name, scope) is loaded and
    reasonably fresh, reloading it if needed. Centralised here so this
    check runs once per request (or once per bulk request covering many
    faces) instead of once per face, which is what the old per-face
    endpoint was effectively doing when called in a loop.

    `collection` and `untagged` are independent, stackable filters (see
    build_face_query) — both can be set at once, e.g. "untagged faces in
    collection 5".

    Freshness is judged on two signals, both scoped by the SAME WHERE
    clause used in load_vectors (via build_face_query), so the check is
    always asking "has the exact pool this index was built from changed?"
    rather than checking the whole table:
      - max_ref:   bumps when a NEW face row matching the scope appears
                   (a new face inserted, or an existing face's node
                   changing such that it now enters/exits the scope).
      - row_count: catches changes that don't touch `ref` at all — e.g.
                   an existing row getting tagged via a plain SQL UPDATE
                   elsewhere (faces_tag() in PHP). If the scope includes
                   `untagged`, a tag event REMOVES a row from the count;
                   otherwise it's unaffected. Either direction of change
                   in row_count is a signal to reload.
    """
    key = cache_key(db_name, collection, untagged)
    now = datetime.utcnow()

    should_reload = False

    if key not in db_indexes:
        should_reload = True
    else:
        last_used = db_indexes[key].get("last_used")
        max_known_ref = db_indexes[key].get("max_ref", 0)
        known_row_count = db_indexes[key].get("row_count", 0)

        if now - last_used > timedelta(hours=1):
            print(f"Cache for '{key}' is older than 1 hour. Refreshing.")
            should_reload = True
        else:
            where_sql, params = build_face_query(untagged, collection)

            conn = get_mysql_connection(db_name)
            cursor = conn.cursor()
            cursor.execute(
                f"SELECT MAX(ref), COUNT(*) FROM resource_face{where_sql}",
                params
            )
            row = cursor.fetchone()
            conn.close()

            latest_ref = row[0] if row and row[0] is not None else 0
            latest_row_count = row[1] if row and row[1] is not None else 0

            if latest_ref > max_known_ref:
                print(f"New faces detected for '{key}'. Reloading vectors.")
                should_reload = True
            elif latest_row_count != known_row_count:
                print(
                    f"Row count changed for '{key}' "
                    f"({known_row_count} -> {latest_row_count}). Reloading vectors."
                )
                should_reload = True

    if should_reload:
        load_vectors(db_name, collection, untagged)

    if key not in db_indexes:
        scope_desc = f"collection {collection}" if collection else "all"
        if untagged:
            scope_desc += " (untagged only)"
        raise HTTPException(status_code=500, detail=f"Unable to load vector index for database '{db_name}' / {scope_desc}")

    db_indexes[key]["last_used"] = now
    return key


@app.post("/extract_faces")
async def extract_faces(file: UploadFile = File(...)):
    try:
        contents = await file.read()
        image = cv2.imdecode(np.frombuffer(contents, np.uint8), cv2.IMREAD_COLOR)
        if image is None:
            raise ValueError("Could not decode image")

        faces = face_app.get(image)
        results = []
        for face in faces:
            results.append({
                "bbox": face.bbox.tolist(),
                "embedding": face.embedding.tolist(),
                "det_score": float(face.det_score)
            })
        return results
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))

@app.post("/find_similar_faces")
async def find_similar_faces(request: FaceSearchRequest):

    db_name = request.db
    collection = request.collection
    untagged = request.untagged
    key = ensure_index_loaded(db_name, collection, untagged)

    conn = get_mysql_connection(db_name)
    cursor = conn.cursor()
    cursor.execute("SELECT vector_blob FROM resource_face WHERE ref = %s", (request.ref,))
    row = cursor.fetchone()
    conn.close()

    if not row:
        raise HTTPException(status_code=404, detail="Face vector not found")

    query_vector = np.frombuffer(row[0], dtype=np.float32)
    query_vector = query_vector / np.linalg.norm(query_vector)
    query_vector = query_vector.reshape(1, -1)

    face_index = db_indexes[key]["index"]
    metadata = db_indexes[key]["metadata"]

    distances, indices = face_index.search(query_vector, request.k + 1)

    matches = []
    for dist, idx in zip(distances[0], indices[0]):
        if idx < 0 or idx >= len(metadata):
            continue

        match = metadata[idx].copy()

        if match["ref"] == request.ref:
            continue

        similarity = float(round(dist, 4))
        if similarity >= request.threshold:
            match["similarity"] = similarity
            matches.append(match)
            print(f"Match: ref={match['ref']} similarity={match['similarity']:.4f}")

    matches.sort(key=lambda x: -x["similarity"])
    return matches

@app.post("/find_similar_faces_bulk")
async def find_similar_faces_bulk(request: FaceBulkSearchRequest):
    """Same matching logic as /find_similar_faces, but for many refs in one
    call. Instead of N separate FAISS searches (and N separate MySQL round
    trips to fetch each query vector), this does ONE bulk vector fetch and
    ONE batched FAISS search — FAISS natively accepts a matrix of query
    vectors and searches all of them in a single vectorized call. This is
    what turns an O(N) page load (N faces = N HTTP requests) into O(1).

    Returns a dict keyed by ref (as a string, since JSON object keys must
    be strings) -> list of matches, same shape as the single-face endpoint.
    """
    db_name = request.db
    collection = request.collection
    untagged = request.untagged
    key = ensure_index_loaded(db_name, collection, untagged)

    refs = [int(r) for r in request.refs]
    if not refs:
        return {}

    # One query for every requested face's vector, instead of one query per face.
    conn = get_mysql_connection(db_name)
    cursor = conn.cursor()
    placeholders = ",".join(["%s"] * len(refs))
    cursor.execute(
        f"SELECT ref, vector_blob FROM resource_face WHERE ref IN ({placeholders})",
        refs
    )
    rows = cursor.fetchall()
    conn.close()

    vector_by_ref = {}
    for ref, blob in rows:
        v = np.frombuffer(blob, dtype=np.float32)
        norm = np.linalg.norm(v)
        if norm > 0:
            v = v / norm
        vector_by_ref[ref] = v

    # Keep only refs we actually found a vector for, preserving a stable order
    # so we can map result rows back to the ref they belong to.
    ordered_refs = [r for r in refs if r in vector_by_ref]
    if not ordered_refs:
        return {}

    query_matrix = np.array([vector_by_ref[r] for r in ordered_refs]).astype('float32')

    face_index = db_indexes[key]["index"]
    metadata = db_indexes[key]["metadata"]

    # Single batched search across every query vector at once.
    distances, indices = face_index.search(query_matrix, request.k + 1)

    response = {}
    for row_i, ref in enumerate(ordered_refs):
        matches = []
        for dist, idx in zip(distances[row_i], indices[row_i]):
            if idx < 0 or idx >= len(metadata):
                continue

            match = metadata[idx].copy()

            if match["ref"] == ref:
                continue

            similarity = float(round(dist, 4))
            if similarity >= request.threshold:
                match["similarity"] = similarity
                matches.append(match)

        matches.sort(key=lambda x: -x["similarity"])
        response[str(ref)] = matches

    return response

if __name__ == "__main__":
    uvicorn.run("faces_service:app", host="127.0.0.1", port=args.port)