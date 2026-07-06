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

# Dictionary to hold FAISS index and metadata per (database, collection)
db_indexes = {}


def cache_key(db_name, collection):
    """Cache key must include collection, otherwise a request for one
    collection can silently reuse another collection's cached index."""
    return f"{db_name}::{collection or 'all'}"


# DB connection helper
def get_mysql_connection(db_name):
    return mysql.connector.connect(
        host=args.db_host,
        database=db_name,
        user=args.db_user,
        password=args.db_pass
    )

# Load vectors from MySQL for a given database (+ optional collection)
def load_vectors(db_name, collection):
    conn = get_mysql_connection(db_name)
    cursor = conn.cursor()
    if collection:
        cursor.execute("""
            SELECT ref, resource, vector_blob, node
            FROM resource_face
            WHERE resource IN (
                SELECT resource
                FROM collection_resource
                WHERE collection = %s
            )
        """, (collection,))
    else:
        cursor.execute("SELECT ref, resource, vector_blob, node FROM resource_face")
    results = cursor.fetchall()
    conn.close()

    if not results:
        print(f"No face vectors found for '{db_name}' / collection {collection}.")
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

    d = len(vectors[0])
    index = faiss.IndexFlatIP(d)
    index.add(np.array(vectors).astype('float32'))

    key = cache_key(db_name, collection)
    db_indexes[key] = {
        "index": index,
        "metadata": index_to_metadata,
        "vectors": np.array(vectors).astype('float32'),
        "last_used": datetime.utcnow(),
        "max_ref": max_ref,
        "collection": collection,
    }
    print(f"Loaded {len(vectors)} vectors for key '{key}'.")

# Request model for similarity search
class FaceSearchRequest(BaseModel):
    ref: int
    db: str
    threshold: float = 0.0
    k: int = 10
    collection: Optional[int] = None

# Request model for bulk similarity search — same as above but takes a list
# of refs and returns matches for all of them from a single FAISS call.
class FaceBulkSearchRequest(BaseModel):
    refs: List[int]
    db: str
    threshold: float = 0.0
    k: int = 10
    collection: Optional[int] = None

def ensure_index_loaded(db_name, collection):
    """Make sure the FAISS index for (db_name, collection) is loaded and
    reasonably fresh, reloading it if needed. Centralised here so this
    check runs once per request (or once per bulk request covering many
    faces) instead of once per face, which is what the old per-face
    endpoint was effectively doing when called in a loop."""
    key = cache_key(db_name, collection)
    now = datetime.utcnow()

    should_reload = False

    if key not in db_indexes:
        should_reload = True
    else:
        last_used = db_indexes[key].get("last_used")
        max_known_ref = db_indexes[key].get("max_ref", 0)

        if now - last_used > timedelta(hours=1):
            print(f"Cache for '{key}' is older than 1 hour. Refreshing.")
            should_reload = True
        else:
            conn = get_mysql_connection(db_name)
            cursor = conn.cursor()
            cursor.execute("SELECT MAX(ref) FROM resource_face")
            row = cursor.fetchone()
            conn.close()
            latest_ref = row[0] if row and row[0] is not None else 0
            if latest_ref > max_known_ref:
                print(f"New faces detected in '{db_name}'. Reloading vectors.")
                should_reload = True

    if should_reload:
        load_vectors(db_name, collection)

    if key not in db_indexes:
        raise HTTPException(status_code=500, detail=f"Unable to load vector index for database '{db_name}' / collection {collection}")

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

from datetime import datetime

def debug_log(message):
    log_file = "/Users/libraryad/Desktop/debuglog.txt"

    try:
        timestamp = datetime.utcnow().isoformat()

        if isinstance(message, (dict, list)):
            message = str(message)

        with open(log_file, "a") as f:
            f.write(f"[{timestamp}] {message}\n")

    except Exception:
        pass

@app.post("/find_similar_faces")
async def find_similar_faces(request: FaceSearchRequest):

    db_name = request.db
    collection = request.collection
    key = ensure_index_loaded(db_name, collection)

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
    key = ensure_index_loaded(db_name, collection)

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