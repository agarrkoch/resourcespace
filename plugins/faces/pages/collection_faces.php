<?php

include '../../../include/boot.php';
include '../../../include/authenticate.php';

if (!checkperm('e0'))
    {
    exit($lang['error-permissiondenied']);
    }

require_once dirname(__FILE__, 2) . "/lib/faces_debug.php";
require_once dirname(__FILE__, 2) . "/lib/faces_render.php";

include_once "../../../include/header.php";

// This page originally only tried a jpg preview (unlike unnamed_faces.php's
// broader ["jpg","png","gif"]) — kept as-is here to avoid changing behavior
// as part of this refactor.
$preview_extensions = ["jpg"];

$collection = getval("collection", 0, true);

if ($collection <= 0)
    {
    exit("No collection specified.");
    }

// Optional: ?untagged=1 restricts this collection's faces to only the
// unnamed ones (node IS NULL), same stackable filter used by
// unnamed_faces.php and the faces_service API (build_face_query).
$untagged = (bool) getval("untagged", 0, true);

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
echo '<link rel="stylesheet" href="' . escape($baseurl_short) . 'plugins/faces/assets/faces.css">';
?>
<?php
if ($untagged)
    {
    echo '<p>Showing unnamed faces only in this collection. '
        . '<a href="' . generateURL("{$baseurl_short}plugins/faces/pages/collection_faces.php", array(), array("collection" => $collection)) . '">(show all faces)</a></p>';
    }
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
";

if ($untagged)
    {
    $sql .= " AND rf.node IS NULL";
    }

$sql .= " ORDER BY rf.resource, rf.ref";

$params = [];
foreach ($resource_refs as $r)
    {
    $params[] = "i";
    $params[] = $r;
    }

$faces = ps_query($sql, $params);

if (empty($faces))
    {
    echo $untagged ? "<p>No unnamed faces found in collection.</p>" : "<p>No faces found in collection.</p>";
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



/**
 * -----------------------------
 * CLUSTERING
 * -----------------------------
 */

require_once dirname(__FILE__, 2) . "/lib/faces_clustering.php";

$clusters = build_face_clusters(
    $faces,
    $collection,
    $untagged,
    200,   // bulk_k
    $faces_service_endpoint,
    $mysql_db,
    $faces_tag_threshold,
    true   // group_by_existing_node - Phase 0: faces already tagged with
           // the same node are known to be the same person, group them
           // directly before FAISS is even involved.
);

if ($clusters === false)
    {
    echo "<p>Error: could not reach faces_service for similarity lookup.</p>";
    echo "</div>";
    include_once "../../../include/footer.php";
    exit;
    }


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
        [$face_path, $face_url] = find_face_preview($face["resource"], $preview_extensions);

        if ($face_path === null)
            {
            continue;
            }

        list($image_width, $image_height) = getimagesize($face_path);

        $style = compute_face_thumb_style($face, $image_width, $image_height, $face_url);

        if ($style === false)
            {
            continue;
            }

        $has_name = !empty($face["node"]) && isset($node_names[$face["node"]]);

        echo render_face_card($face, [
            'style' => $style,
            'lazy' => false,
            'has_name' => $has_name,
            'display_name' => $has_name ? $node_names[$face["node"]] : '',
            'dynamic_keywords_available' => $dynamic_keywords_available,
            'faces_tag_field_data' => $faces_tag_field_data,
        ]);
        }

    echo "</div>";
    echo "</div>";
    echo "</div>";
    echo "</div>";
    }

?>

<script>
<?php include dirname(__FILE__, 2) . "/lib/faces_shared_js.php"; ?>

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

// Overrides the shared default from faces_shared_js.php to also propagate
// the new name to the rest of this face's cluster.
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
</script>

</div>

<?php include_once "../../../include/footer.php"; ?>