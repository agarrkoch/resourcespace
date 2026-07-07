<?php

include '../../../include/boot.php';
include '../../../include/authenticate.php';

if (!checkperm('e0'))
    {
    exit($lang['error-permissiondenied']);
    }

include_once "../../../include/header.php";

$collection = getval("collection", 0, true);

if ($collection <= 0)
    {
    exit("No collection specified.");
    }

$collection_data = get_collection($collection);

if ($collection_data === false)
    {
    exit("Collection not found.");
    }

/**
 * Get breadcrumb collection trail and created headers
 */

global $enable_themes, $baseurl_short;
 
$full_collection_trail = array();
 
if (
    $enable_themes
    && isset($collection_data) && $collection_data !== false
    && $collection_data["type"] == COLLECTION_TYPE_FEATURED
)
    {
    $full_collection_trail[] = array(
        "title" => $lang["themes"],
        "href"  => generateURL("{$baseurl_short}pages/collections_featured.php", array())
    );
 
    $fc_branch_path = move_featured_collection_branch_path_root(
        get_featured_collection_category_branch_by_leaf((int)$collection_data["parent"], [])
    );
 
    $branch_trail = array_map(function ($branch) use ($baseurl_short)
        {
        return array(
            "title" => i18n_get_translated($branch["name"]),
            "href"  => generateURL(
                "{$baseurl_short}pages/collections_featured.php",
                array(),
                array("parent" => $branch["ref"])
            ),
        );
        }, $fc_branch_path);
 
    $full_collection_trail = array_merge($full_collection_trail, $branch_trail);
    }
else
    {
    $ancestor_trail = get_collection_parent_chain($collection);
    array_pop($ancestor_trail);
 
    foreach ($ancestor_trail as $ancestor)
        {
        $full_collection_trail[] = array(
            "title" => $ancestor['name'],
            "href"  => generateURL("{$baseurl_short}pages/collections.php", array("ref" => $ancestor['ref'])),
        );
        }
    }
 
$current_collection_search_params = array("search" => "!collection" . $collection);
 
$full_collection_trail[] = array(
    "title" => i18n_get_collection_name($collection_data),
    "href"  => generateURL("{$baseurl_short}pages/search.php", $current_collection_search_params),
);
 
ob_start();
renderBreadcrumbs($full_collection_trail, '', 'BreadcrumbsBoxSlim BreadcrumbsBoxTheme');
$renderBreadcrumbs = ob_get_contents();
ob_end_clean();
 
echo '<div class="BasicsBox">';
echo '<h1><i class="plugin-icon fa-xl fa fa-user-tag" style="color:seagreen;"></i> Face Clusters</h1>';
echo $renderBreadcrumbs;
echo '<div style="border-bottom:1px solid #c1c1c1; box-shadow:0 12px 10px -12px #bbbbbb; margin:15px 0 20px 0;"></div>';

/**
 * Get resources in collection
 */
$resources = ps_query(
    "SELECT resource
       FROM collection_resource
      WHERE collection = ?
      ORDER BY resource",
    ["i", $collection]
);

$resource_refs = array_column($resources, "resource");

if (empty($resource_refs))
    {
    echo "<p>No resources in collection.</p>";
    echo "</div>";
    include_once "../../../include/footer.php";
    exit;
    }

/**
 * Load faces + embeddings
 */
$sql = "
SELECT
    rf.ref,
    rf.resource,
    rf.det_score,
    rf.bbox,
    rf.node,
    rf.vector_blob
FROM resource_face rf
WHERE rf.resource IN (" . implode(",", array_fill(0, count($resource_refs), "?")) . ")
ORDER BY rf.resource, rf.ref
";

$params = [];
foreach ($resource_refs as $r)
    {
    $params[] = "i";
    $params[] = $r;
    }

$faces = ps_query($sql, $params);

if (empty($faces))
    {
    echo "<p>No faces found in collection.</p>";
    echo "</div>";
    include_once "../../../include/footer.php";
    exit;
    }

/**
 * Look up display names for any faces that are already tagged (node != 0),
 * so we can show the existing name instead of forcing a re-lookup per face.
 */
$node_refs = array_values(array_unique(array_filter(array_column($faces, "node"))));
$node_names = [];
if (!empty($node_refs))
    {
    $node_rows = ps_query(
        "SELECT ref, name FROM node WHERE ref IN (" . implode(",", array_map("intval", $node_refs)) . ")"
    );
    foreach ($node_rows as $node_row)
        {
        $node_names[$node_row["ref"]] = $node_row["name"];
        }
    }

/**
 * Set up the dynamic keywords field used for tagging faces with a name,
 * same field referenced by HookFacesViewCustompanels().
 */
global $faces_tag_field, $lang;
$faces_tag_field_data = get_resource_type_field($faces_tag_field);
$dynamic_keywords_available = is_array($faces_tag_field_data)
    && $faces_tag_field_data["type"] == FIELD_TYPE_DYNAMIC_KEYWORDS_LIST;
if ($dynamic_keywords_available)
    {
    $faces_tag_field_data["node_options"] = get_nodes($faces_tag_field_data["ref"], null, false);
    }

function debug_log($message)
    {

    $log_file = "/Users/libraryad/Desktop/debuglog2.txt";

    try
        {
        $timestamp = date("Y-m-d\TH:i:s.u");

        // Convert arrays/objects safely
        if (is_array($message) || is_object($message))
            {
            $message = print_r($message, true);
            }
        elseif ($message instanceof Exception)
            {
            $message = $message->getMessage() . "\n" . $message->getTraceAsString();
            }

        file_put_contents(
            $log_file,
            "[$timestamp] " . $message . PHP_EOL,
            FILE_APPEND
        );

        }
    catch (Exception $e)
        {
        // never break execution if logging fails
        }
    }


/**
 * -----------------------------
 * CLUSTERING
 * -----------------------------
 */

$clusters = [];

// --- Union-Find (disjoint set) helpers -------------------------------
// Lets a face that bridges two previously-separate groups MERGE them,
// instead of being dropped or forming its own singleton cluster.
$parent = [];

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

// Index every face in the collection by ref, so matches returned by FAISS
// (already scoped to this collection) can be looked up without another
// DB round trip.
$face_by_ref = [];
foreach ($faces as $face)
    {
    $face_by_ref[$face['ref']] = $face;
    }

// Phase 0: faces already tagged with the same name (node) are known to be
// the same person — group them directly. This is authoritative (someone
// already confirmed it), costs nothing, and doesn't depend on embeddings
// or FAISS at all.
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

// Calls the faces_service bulk endpoint for a list of refs, retrying a
// couple of times on failure. Unlike the old per-face loop, this is
// normally just ONE HTTP request for the whole collection — the bulk
// endpoint does one vectorized FAISS search server-side instead of us
// making N separate requests.
function call_faces_service_bulk($refs, $threshold, $faces_service_endpoint, $mysql_db, $collection, $max_attempts = 3)
    {
    if (empty($refs))
        {
        return [];
        }

    $payload = [
        'refs' => array_values(array_map('intval', $refs)),
        'db' => $mysql_db,
        'threshold' => $threshold,
        'k' => 200,
        'collection' => $collection,
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
        // One request now covers every face, so give it real headroom
        // instead of the short per-face timeout that made sense before.
        curl_setopt($ch, CURLOPT_TIMEOUT, 90);

        debug_log(
            "BULK REQUEST (attempt $attempt): " . count($refs)
            . " refs, collection=" . var_export($collection, true)
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
            usleep(300000 * $attempt); // brief backoff: 300ms, 600ms, ...
            }
        }

    return null; // every attempt failed
    }

// Raise the bar used for auto-merging above whatever base
// threshold is configured, without touching that config value itself.
// Fewer, higher-confidence candidate edges come back from the service to
// begin with.
$threshold_margin = 0.05;
$effective_primary_threshold = min(1.0, $faces_tag_threshold + $threshold_margin);

// Phase 1: one bulk call gets similarity matches for every face in the
// collection at once, instead of one HTTP round trip (and one fresh
// MySQL connection) per face. See faces_service.py's
// /find_similar_faces_bulk for the batched FAISS search this relies on.
$all_refs = array_column($faces, 'ref');
$bulk_results = call_faces_service_bulk(
    $all_refs,
    $effective_primary_threshold,
    $faces_service_endpoint,
    $mysql_db,
    $collection
);

if ($bulk_results === null)
    {
    echo "<p>Error: could not reach faces_service for similarity lookup.</p>";
    echo "</div>";
    include_once "../../../include/footer.php";
    exit;
    }

// A blurry/low-quality face detection produces a noisier embedding, which
// can weakly (but wrongly) resemble multiple different people. Letting
// that bridge two otherwise-unrelated clusters together is worse than
// missing a real match, so low-confidence faces need a stronger-than-usual
// similarity before they're allowed to trigger a merge.
$min_bridge_det_score = 0.5;
$low_confidence_similarity_margin = 0.1;

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

// A single matching pair is not enough evidence to merge two
// GROUPS together — one spurious link (or one link between an otherwise
// unrelated pair) shouldn't fuse two clusters permanently. Instead we
// collect every candidate edge first, then only merge two groups once
// enough independent edges connect them.
//
// The requirement scales with how small the smaller group is: a
// singleton (a straggler with no group yet) can only ever produce ONE
// edge into anything else, so requiring 2 there would make it impossible
// for a lone face to ever join a cluster. Real multi-member groups need 2.
function corroboration_requirement($size_a, $size_b)
    {
    return min(2, min($size_a, $size_b));
    }

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

        // Matches should already be scoped to this collection, but guard
        // against a stray ref that isn't in our face list.
        if (!isset($face_by_ref[$r]))
            {
            continue;
            }

        // Each real-world pair shows up twice in bulk results (once from
        // each face's own match list, since similarity is symmetric) —
        // only keep it once so it isn't double-counted as 2 corroborating
        // edges when it's really just 1 connection.
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

// Repeatedly: count corroborating edges between each pair of CURRENT
// groups, merge any pair that clears its (size-scaled) requirement, then
// recompute — since a merge changes group sizes/roots, which can unlock
// further merges. Stops once a pass makes no new merges.
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

        // Roots may have shifted from an earlier merge this same round —
        // re-resolve before checking.
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

// Final grouping: everything not confidently matched simply stays as its
// own single-face group — we deliberately never loosen the threshold to
// force a merge. A face with no confident match is more useful shown on
// its own (for manual tagging) than silently merged in on shaky evidence.
$groups = [];
foreach ($faces as $face)
    {
    $root = uf_find($parent, $face['ref']);
    $groups[$root][] = $face;
    }

// Pick a stable representative per group (lowest ref) and build clusters,
// largest first so the most significant groupings surface at the top.
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


?>

<?php

$cluster_id = 0;

foreach ($clusters as $cluster)
    {
    $cluster_id++;

    echo "<div class='RecordBox' id='cluster_box_" . $cluster_id . "'>";
    echo "<div class='RecordPanel'>";

    echo "<div class='Title'>";
    echo "Cluster #" . $cluster_id . " — " . count($cluster["faces"]) . " faces";
    echo "</div>";

    echo "<div class='Listview'>";
    echo "<div style='display:flex;flex-wrap:wrap;gap:36px 28px;padding:16px;'>";

    foreach ($cluster["faces"] as $face)
        {
		
        $face_path = get_resource_path($face["resource"], true, "scr", false, "jpg");
        $face_url  = get_resource_path($face["resource"], false, "scr", false, "jpg");

        if (!file_exists($face_path))
            {
            $face_path = get_resource_path($face["resource"], true, "", false, "jpg");
            $face_url  = get_resource_path($face["resource"], false, "", false, "jpg");
            }

        if (!file_exists($face_path))
            {
            continue;
            }

        list($image_width, $image_height) = getimagesize($face_path);

        $bbox = json_decode($face["bbox"], true);

        if (!is_array($bbox))
            {
            continue;
            }

        list($x1, $y1, $x2, $y2) = $bbox;

        $face_width  = $x2 - $x1;
        $face_height = $y2 - $y1;

        if ($face_width <= 0 || $face_height <= 0)
            {
            continue;
            }

        // Scale so the ENTIRE bbox fits inside the 120x120 box (letterboxed),
        // instead of scaling to fill it and cropping off part of the face.
        $box_size = 120;
        $scale = min($box_size / $face_width, $box_size / $face_height);

        $crop_width  = $face_width * $scale;
        $crop_height = $face_height * $scale;

        // Center the scaled bbox within the box; whatever space is left
        // over on the shorter axis becomes the letterbox bar.
        $offset_x = ($box_size - $crop_width) / 2;
        $offset_y = ($box_size - $crop_height) / 2;

        $bg_pos_x = $offset_x - ($x1 * $scale);
        $bg_pos_y = $offset_y - ($y1 * $scale);

        $bg_size_x = $image_width * $scale;
        $bg_size_y = $image_height * $scale;

        $style = sprintf(
            "width:120px;height:120px;
            background-color:#111;
            background-image:url('%s');
            background-position:%.1fpx %.1fpx;
            background-size:%.1fpx %.1fpx;
            background-repeat:no-repeat;
            border:1px solid #ccc;",
            $face_url,
            $bg_pos_x,
            $bg_pos_y,
            $bg_size_x,
            $bg_size_y
        );

        $search_url = generateURL(
            $baseurl . "/pages/search.php",
            ["search" => "!face" . $face["ref"]]
        );

        $view_url = generateURL(
            $baseurl . "/pages/view.php",
            ["ref" => $face["resource"]]
        );

        ?>

        <div id="face_card_<?php echo $face["ref"]; ?>"
             data-resource="<?php echo (int)$face["resource"]; ?>"
             style="text-align:left; width:120px;">

			<a href="<?php echo $view_url; ?>"
			   target="_blank"
			   rel="noopener">
			    <div style="<?php echo $style ?>"></div>
			</a>

            <div>
                Face #<?php echo $face["ref"]; ?>
            </div>

           <?php
           $has_name = !empty($face["node"]) && isset($node_names[$face["node"]]);
           ?>

           <div id="face_name_<?php echo $face["ref"]; ?>"
                style="color: <?php echo $has_name ? 'green' : 'red'; ?>;">
               <?php
               echo $has_name
                   ? escape($node_names[$face["node"]])
                   : escape($lang["faces-noname"] ?? "No name");
               ?>
           </div>

            <?php
            $has_name = !empty($face["node"]) && isset($node_names[$face["node"]]);
            ?>

            <div>

                <div>
                    <a href="#"
                       onclick="return ToggleNameEditor(
                           <?php echo (int)$face["resource"]; ?>,
                           <?php echo (int)$face["ref"]; ?>
                       );">
                        <i class="fa fa-fw fa-tag"></i>&nbsp;<span id="face_rename_label_<?php echo $face["ref"]; ?>">
                            <?php echo $has_name ? "Rename" : "Add name"; ?>
                        </span>
                    </a>
                </div>

                <div>
                    <a href="#"
                       onclick="return DeleteFace(
                           <?php echo (int)$face["resource"]; ?>,
                           <?php echo (int)$face["ref"]; ?>
                       );">
                        <i class="fa fa-fw fa-trash"></i>&nbsp;Delete
                    </a>
                </div>
                
                <div>
                    <a href="<?php echo $search_url; ?>"
                       onclick="return CentralSpaceLoad(this,true);">
                        <i class="fa fa-fw fa-search"></i>&nbsp;Find
                    </a>
                </div>
            </div>

            <div id="face_editor_<?php echo $face["ref"]; ?>"
                 class="face-name-editor"
                 style="display:none; text-align:left; margin-top:6px;">
                <?php
                if ($dynamic_keywords_available)
                    {
                    $field = $faces_tag_field_data;
                    $name = "face_" . $face["ref"];
                    $selected_nodes = array($face["node"]);
                    $multiple = false;
                    include dirname(__FILE__, 4) . '/pages/edit_fields/9.php';
                    }
                else
                    {
                    echo escape($lang["faces-tag-field-not-set"] ?? "Tag field not configured");
                    }
                ?>
            </div>

        </div>

        <?php
        }

    echo "</div>";
    echo "</div>";
    echo "</div>";
    echo "</div>";
    }

?>

<script>
// Tracks which face's name editor is currently open, since AutoSave() below
// is called generically by the included dynamic keywords field and needs to
// know which single face to save (unlike the resource-view hook, this page
// has faces from many different resources, so we can't rebuild a whole
// resource's field value the way HookFacesViewCustompanels() does).
var currentEditingFace = null;

function ToggleNameEditor(resource, face)
    {
    var editor = document.getElementById("face_editor_" + face);
    if (!editor)
        {
        return false;
        }

    var opening = (editor.style.display === "none" || editor.style.display === "");

    // Close any other open editor first so only one is active at a time.
    document.querySelectorAll(".face-name-editor").forEach(function (el)
        {
        if (el !== editor)
            {
            el.style.display = "none";
            }
        });

    editor.style.display = opening ? "block" : "none";
    currentEditingFace = opening ? {resource: resource, face: face} : null;

    return false;
    }

// Shared text for "unnamed" faces, used both to render it and to detect it.
var NO_NAME_TEXT = <?php echo json_encode($lang["faces-noname"] ?? "No name"); ?>;

function updateFaceNameDisplay(face, name)
    {
    var nameEl = document.getElementById("face_name_" + face);
    if (nameEl)
        {
        nameEl.textContent = name ? name : NO_NAME_TEXT;
        nameEl.style.color = name ? "green" : "red";
        }

    var labelEl = document.getElementById("face_rename_label_" + face);
    if (labelEl)
        {
        labelEl.textContent = name ? "Rename" : "Add name";
        }
    }

/**
 * Assigns a metadata node (tag) to a detected face using the ResourceSpace API in native mode.
 */
function FacesUpdateTag(resource, face, node)
    {
    api(
        "faces_set_node",
        {"resource": resource, "face": face, "node": node},
        null,
        <?php echo generate_csrf_js_object('faces_tag'); ?>
    );
    }

// When a face is given a name, apply that same name to every other face in
// the same cluster that doesn't have one yet — they're the same person, so
// naming one is a signal for the rest of the (still unnamed) group too.
function propagateNameToCluster(taggedFace, nodeId, displayName)
    {
    var taggedCard = document.getElementById("face_card_" + taggedFace);
    if (!taggedCard)
        {
        return;
        }

    var clusterBox = taggedCard.closest("[id^='cluster_box_']");
    if (!clusterBox)
        {
        return;
        }

    var cards = clusterBox.querySelectorAll("[id^='face_card_']");
    cards.forEach(function (card)
        {
        var cardFace = card.id.replace("face_card_", "");
        if (cardFace === String(taggedFace))
            {
            return;
            }

        var nameEl = document.getElementById("face_name_" + cardFace);
        if (!nameEl || nameEl.textContent.trim() !== NO_NAME_TEXT)
            {
            return; // already named — don't overwrite an existing tag
            }

        var cardResource = card.getAttribute("data-resource");
        FacesUpdateTag(cardResource, cardFace, nodeId);
        updateFaceNameDisplay(cardFace, displayName);
        });
    }

// Called by the included dynamic keywords field when a keyword is picked or
// cleared. Saves only the single face currently being edited.

// A selected keyword chip includes its own "remove" control (the small "x"
// used to deselect it) as a nested element. Reading .textContent directly
// pulls that in too (e.g. "Neil Rosen x" instead of "Neil Rosen"). Strip
// those controls out structurally first, rather than guessing at the
// character used, so the extracted name is clean.
function extractKeywordChipName(chipEl)
    {
    var clone = chipEl.cloneNode(true);
    var removers = clone.querySelectorAll("a, button, span[onclick], .remove, .keywordremove, .kwremove");
    removers.forEach(function (r) { r.remove(); });
    return (clone.textContent || clone.innerText || "").trim();
    }

function AutoSave(field)
    {
    if (!currentEditingFace)
        {
        return;
        }

    var face = currentEditingFace.face;
    var resource = currentEditingFace.resource;

    var parentDiv = document.getElementById("face_" + face + "_selected");
    var children = parentDiv ? parentDiv.querySelectorAll(".keywordselected") : [];

    if (children.length === 0)
        {
        FacesUpdateTag(resource, face, 0);
        updateFaceNameDisplay(face, "");
        }
    else
        {
        if (children.length > 1)
            {
            alert(<?php echo json_encode($lang["faces-oneface"] ?? "Only one name can be assigned per face"); ?>);
            for (var i = 1; i < children.length; i++)
                {
                children[i].remove();
                }
            }

        var firstChild = children[0];
        var node = firstChild.id.match(/\d+$/);
        var displayName = extractKeywordChipName(firstChild);
        FacesUpdateTag(resource, face, node[0]);
        updateFaceNameDisplay(face, displayName);
        propagateNameToCluster(face, node[0], displayName);
        }

    var editor = document.getElementById("face_editor_" + face);
    if (editor)
        {
        editor.style.display = "none";
        }
    currentEditingFace = null;
    }

function DeleteFace(resource, face)
    {
    if (!confirm("Delete this detected face?"))
        {
        return false;
        }

    var card = document.getElementById("face_card_" + face);
    var clusterBox = card ? card.closest("[id^='cluster_box_']") : null;

    if (card)
        {
        card.remove();
        }

    if (clusterBox)
        {
        var remaining = clusterBox.querySelectorAll("[id^='face_card_']").length;
        if (remaining === 0)
            {
            clusterBox.remove();
            }
        else
            {
            var titleEl = clusterBox.querySelector(".Title");
            if (titleEl)
                {
                titleEl.textContent = titleEl.textContent.replace(/\d+(?=\s+faces$)/, remaining);
                }
            }
        }

    api(
        "faces_delete_face",
        {
            "resource": resource,
            "face": face
        },
        function (result)
            {
            if (!result)
                {
                // API call failed after we already removed it optimistically —
                // at minimum surface it, since otherwise the UI silently
                // disagrees with the database until the next reload.
                alert("Failed to delete face — please refresh and try again.");
                }
            },
        <?php echo generate_csrf_js_object('faces_delete_face'); ?>
    );

    return false;
    }
</script>

</div>

<?php include_once "../../../include/footer.php"; ?>