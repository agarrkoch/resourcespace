<?php

/**
 * Shared face-clustering logic for the faces plugin.
 *
 * Both unnamed_faces.php and collection_faces.php independently implemented
 * the same union-find + bulk-FAISS + corroboration-rounds pipeline. The only
 * real differences between the two call sites were:
 *   - collection_faces.php runs an extra "Phase 0" pass that groups faces
 *     already tagged with the same node before FAISS is even involved
 *     (unnamed_faces.php has nothing to group there, since every face on
 *     that page has node IS NULL by construction).
 *   - the bulk lookup's k differs (30 vs 200) and the untagged flag is
 *     either a hardcoded true or a passed-through URL param.
 *
 * build_face_clusters() takes those as parameters so each page can keep its
 * own behavior while sharing everything else.
 */

if (!function_exists('build_face_clusters'))
    {
    function uf_find(&$parent, $x)
        {
        if (!isset($parent[$x]))
            {
            $parent[$x] = $x;
            }
        if ($parent[$x] !== $x)
            {
            $parent[$x] = uf_find($parent, $parent[$x]);
            }
        return $parent[$x];
        }

    function uf_union(&$parent, $x, $y)
        {
        $rx = uf_find($parent, $x);
        $ry = uf_find($parent, $y);
        if ($rx !== $ry)
            {
            $parent[$rx] = $ry;
            }
        }
    // -----------------------------------------------------------------------

    // Calls the faces_service bulk endpoint for a list of refs, retrying a
    // couple of times on failure. One HTTP request covers the whole page of
    // faces — the bulk endpoint does one vectorized FAISS search server-side.
    //
    // $collection and $untagged are passed straight through as the service's
    // two independent, stackable scope filters (see faces_service.py's
    // build_face_query).
    function call_faces_service_bulk($refs, $threshold, $faces_service_endpoint, $mysql_db, $collection, $untagged = false, $k = 30, $max_attempts = 3)
        {
        if (empty($refs))
            {
            return [];
            }

        $payload = [
            'refs' => array_values(array_map('intval', $refs)),
            'db' => $mysql_db,
            'threshold' => $threshold,
            'k' => $k,
            'collection' => $collection,
            'untagged' => $untagged,
        ];
        $json = json_encode($payload);

        for ($attempt = 1; $attempt <= $max_attempts; $attempt++)
            {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $faces_service_endpoint . "/find_similar_faces_bulk");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Connection: keep-alive',
                'Expect:'
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
            curl_setopt($ch, CURLOPT_TIMEOUT, 90);

            debug_log(
                "BULK REQUEST (attempt $attempt): " . count($refs)
                . " refs, collection=" . var_export($collection, true)
                . ", untagged=" . var_export($untagged, true)
                . ", threshold=$threshold"
            );

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            debug_log("BULK RESPONSE (attempt $attempt, HTTP $http_code, " . strlen((string)$response) . " bytes)");

            if ($http_code === 200 && !empty($response))
                {
                $decoded = json_decode($response, true);
                if (is_array($decoded))
                    {
                    return $decoded;
                    }
                }

            if ($attempt < $max_attempts)
                {
                usleep(300000 * $attempt);
                }
            }

        return null;
        }

    // A blurry/low-quality face detection produces a noisier embedding, which
    // can weakly (but wrongly) resemble multiple different people. Letting
    // that bridge two otherwise-unrelated clusters together is worse than
    // missing a real match, so low-confidence faces need a stronger-than-usual
    // similarity before they're allowed to trigger a merge.
    function should_merge_faces(
        $ref,
        $r,
        $similarity,
        $face_by_ref,
        $base_threshold,
        $min_bridge_det_score,
        $low_confidence_similarity_margin
    )
        {
        $ref_det = $face_by_ref[$ref]['det_score'] ?? 1.0;
        $match_det = $face_by_ref[$r]['det_score'] ?? 1.0;

        $is_low_confidence = ($ref_det < $min_bridge_det_score) || ($match_det < $min_bridge_det_score);

        if (!$is_low_confidence || $similarity === null)
            {
            return true; // normal case — already cleared the threshold server-side
            }

        // At least one face is low-confidence: require extra headroom above
        // the threshold that was actually used for this call, not just a
        // bare pass.
        return $similarity >= ($base_threshold + $low_confidence_similarity_margin);
        }

    // A single matching pair is not enough evidence to merge two GROUPS
    // together — one spurious link (or one link between an otherwise
    // unrelated pair) shouldn't fuse two clusters. The
    // requirement scales with how small the smaller group is: a singleton
    // can only ever produce ONE edge into anything else, so requiring 2
    // there would make it impossible for a lone face to ever join a
    // cluster. Real multi-member groups need 2.
    function corroboration_requirement($size_a, $size_b)
        {
        return min(2, min($size_a, $size_b));
        }

    /**
     * Runs the full clustering pipeline and returns the sorted $clusters
     * array (largest group first), or false if the bulk FAISS call
     * couldn't be completed after retries — callers should treat false as
     * "show an error and stop", the same way the original inline code did.
     *
     * $group_by_existing_node: if true, runs the "Phase 0" pass that groups
     * faces already tagged with the same node before FAISS is involved.
     * unnamed_faces.php should pass false here — every face there has
     * node IS NULL by construction, so the pass would be a costly no-op.
     */
    function build_face_clusters(
        $faces,
        $collection,
        $untagged,
        $bulk_k,
        $faces_service_endpoint,
        $mysql_db,
        $faces_tag_threshold,
        $group_by_existing_node = false
    )
        {
        $parent = [];

        $face_by_ref = [];
        foreach ($faces as $face)
            {
            $face_by_ref[$face['ref']] = $face;
            }

        if ($group_by_existing_node)
            {
            $faces_by_node = [];
            foreach ($faces as $face)
                {
                if (!empty($face['node']))
                    {
                    $faces_by_node[$face['node']][] = $face['ref'];
                    }
                }
            foreach ($faces_by_node as $node => $refs)
                {
                if (count($refs) < 2)
                    {
                    continue;
                    }
                for ($i = 1; $i < count($refs); $i++)
                    {
                    uf_union($parent, $refs[0], $refs[$i]);
                    }
                }
            }

        // Raise the bar used for auto-merging above whatever base threshold
        // is configured, without touching that config value itself. Fewer,
        // higher-confidence candidate edges come back from the service to
        // begin with.
        $threshold_margin = 0.05;
        $effective_primary_threshold = min(1.0, $faces_tag_threshold + $threshold_margin);

        $all_refs = array_column($faces, 'ref');
        $bulk_results = call_faces_service_bulk(
            $all_refs,
            $effective_primary_threshold,
            $faces_service_endpoint,
            $mysql_db,
            $collection,
            $untagged,
            $bulk_k
        );

        if ($bulk_results === null)
            {
            return false;
            }

        $min_bridge_det_score = 0.5;
        $low_confidence_similarity_margin = 0.1;

        $candidate_edges = [];
        foreach ($bulk_results as $ref => $matches)
            {
            $ref = (int)$ref;

            if (!isset($face_by_ref[$ref]) || !is_array($matches))
                {
                continue;
                }

            foreach ($matches as $match)
                {
                $r = $match['ref'];

                if (!isset($face_by_ref[$r]))
                    {
                    continue;
                    }

                // Each real-world pair shows up twice in bulk results (once
                // from each face's own match list, since similarity is
                // symmetric) — only keep it once so it isn't double-counted.
                if ($r <= $ref)
                    {
                    continue;
                    }

                if (!should_merge_faces(
                    $ref,
                    $r,
                    $match['similarity'] ?? null,
                    $face_by_ref,
                    $effective_primary_threshold,
                    $min_bridge_det_score,
                    $low_confidence_similarity_margin
                ))
                    {
                    debug_log(
                        "SKIPPED low-confidence bridge: ref $ref <-> ref $r (similarity "
                        . ($match['similarity'] ?? '?') . ")"
                    );
                    continue;
                    }

                $candidate_edges[] = ['a' => $ref, 'b' => $r, 'similarity' => $match['similarity'] ?? null];
                }
            }

        // Repeatedly: count corroborating edges between each pair of
        // CURRENT groups, merge any pair that clears its (size-scaled)
        // requirement, then recompute — since a merge changes group
        // sizes/roots, which can unlock further merges. Stops once a pass
        // makes no new merges.
        $max_corroboration_rounds = 6;
        for ($round = 0; $round < $max_corroboration_rounds; $round++)
            {
            $pair_counts = []; // "rootA:rootB" => corroborating edge count

            foreach ($candidate_edges as $edge)
                {
                $ra = uf_find($parent, $edge['a']);
                $rb = uf_find($parent, $edge['b']);
                if ($ra === $rb)
                    {
                    continue; // already the same group
                    }
                $key = $ra < $rb ? "$ra:$rb" : "$rb:$ra";
                $pair_counts[$key] = ($pair_counts[$key] ?? 0) + 1;
                }

            if (empty($pair_counts))
                {
                break;
                }

            $component_sizes = [];
            foreach ($faces as $face)
                {
                $root = uf_find($parent, $face['ref']);
                $component_sizes[$root] = ($component_sizes[$root] ?? 0) + 1;
                }

            $merged_any = false;
            foreach ($pair_counts as $key => $count)
                {
                [$ra, $rb] = array_map('intval', explode(':', $key));

                // Roots may have shifted from an earlier merge this same
                // round — re-resolve before checking.
                $ra = uf_find($parent, $ra);
                $rb = uf_find($parent, $rb);
                if ($ra === $rb)
                    {
                    continue;
                    }

                $size_a = $component_sizes[$ra] ?? 1;
                $size_b = $component_sizes[$rb] ?? 1;
                $required = corroboration_requirement($size_a, $size_b);

                if ($count >= $required)
                    {
                    uf_union($parent, $ra, $rb);
                    $merged_any = true;
                    debug_log(
                        "MERGE groups $ra + $rb (sizes $size_a/$size_b, "
                        . "required $required corroborating edges, got $count)"
                    );
                    }
                else
                    {
                    debug_log(
                        "HELD BACK merge $ra + $rb (sizes $size_a/$size_b, "
                        . "required $required corroborating edges, only got $count)"
                    );
                    }
                }

            if (!$merged_any)
                {
                break; // fixed point — nothing new to merge
                }
            }

        // Final grouping: everything not confidently matched simply stays
        // as its own single-face group — we deliberately never loosen the
        // threshold to force a merge. A face with no confident match is
        // more useful shown on its own (for manual tagging) than silently
        // merged in on shaky evidence.
        $groups = [];
        foreach ($faces as $face)
            {
            $root = uf_find($parent, $face['ref']);
            $groups[$root][] = $face;
            }

        $clusters = [];
        foreach ($groups as $group_faces)
            {
            usort($group_faces, function ($a, $b)
                {
                return $a['ref'] <=> $b['ref'];
                });

            $clusters[] = [
                'representative' => $group_faces[0],
                'faces' => $group_faces,
            ];
            }

        usort($clusters, function ($a, $b)
            {
            return count($b['faces']) <=> count($a['faces']);
            });

        return $clusters;
        }
    }
