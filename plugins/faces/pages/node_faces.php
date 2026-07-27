<?php

include '../../../include/boot.php';
include '../../../include/authenticate.php';

if (!checkperm('e0'))
    {
    exit($lang['error-permissiondenied']);
    }

include_once "../../../include/header.php";

require_once dirname(__FILE__, 2) . "/lib/faces_debug.php";
require_once dirname(__FILE__, 2) . "/lib/faces_render.php";

global $baseurl_short;
echo '<link rel="stylesheet" href="' . escape($baseurl_short) . 'plugins/faces/assets/faces.css">';

// This page originally only tried a jpg preview (unlike unnamed_faces.php's
// broader ["jpg","png","gif"]) — kept as-is here to avoid changing behavior
// as part of this refactor. Widen this list if you want the same
// non-jpg-preview robustness unnamed_faces.php has.
$preview_extensions = ["jpg"];

/**
 * Node-based face listing.
 *
 * Unlike face_clusters.php (collection -> similarity clustering), this page
 * just takes a node ref directly and lists every face already tagged with
 * that node. No FAISS lookups, no union-find, no corroboration rounds —
 * the "clustering" here is just "which faces have this node".
 */

$node = getval("node", 0, true);

if ($node <= 0)
    {
    exit("No node specified.");
    }

$node_data = ps_query(
    "SELECT ref, name FROM node WHERE ref = ?",
    ["i", $node]
);

if (empty($node_data))
    {
    exit("Node not found.");
    }

$node_data = $node_data[0];
$node_name = $node_data["name"];

echo '<div class="BasicsBox">';
echo '<h1><i class="plugin-icon fa-xl fa fa-user-tag" style="color:seagreen;"></i> Faces — ' . escape($node_name) . '</h1>';
echo '<div style="border-bottom:1px solid #c1c1c1; box-shadow:0 12px 10px -12px #bbbbbb; margin:15px 0 20px 0;"></div>';

/**
 * Load every face tagged with this node.
 */
$faces = ps_query(
    "SELECT
        rf.ref,
        rf.resource,
        rf.det_score,
        rf.bbox,
        rf.node,
        rf.vector_blob
     FROM resource_face rf
     WHERE rf.node = ?
     ORDER BY rf.resource, rf.ref",
    ["i", $node]
);

if (empty($faces))
    {
    echo "<p>No faces found for this node.</p>";
    echo "</div>";
    include_once "../../../include/footer.php";
    exit;
    }

/**
 * Look up display names for any nodes referenced (should just be $node,
 * but keep it general in case a face's node value ever differs).
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
 * same field referenced by HookFacesViewCustompanels() and face_clusters.php.
 */
global $faces_tag_field, $lang;
$faces_tag_field_data = get_resource_type_field($faces_tag_field);
$dynamic_keywords_available = is_array($faces_tag_field_data)
    && $faces_tag_field_data["type"] == FIELD_TYPE_DYNAMIC_KEYWORDS_LIST;
if ($dynamic_keywords_available)
    {
    $faces_tag_field_data["node_options"] = get_nodes($faces_tag_field_data["ref"], null, false);
    }

echo "<div class='RecordBox' id='node_faces_box'>";
echo "<div class='RecordPanel'>";

echo "<div class='Title'>";
echo escape($node_name) . " — " . count($faces) . " faces";
echo "</div>";

echo "<div class='Listview'>";
echo "<div style='display:flex;flex-wrap:wrap;gap:36px 28px;padding:16px;'>";

foreach ($faces as $face)
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

?>

<script>
<?php include dirname(__FILE__, 2) . "/lib/faces_shared_js.php"; ?>

// This page has no cluster boxes (it's a single node's faces, listed
// flat) — decrement the one flat "Title" count of faces shown, instead
// of the per-cluster relabeling the shared removeFaceCard()/DeleteFace()
// do. Overrides the shared default from faces_shared_js.php.
function DeleteFace(resource, face)
    {
    if (!confirm("Delete this detected face?"))
        {
        return false;
        }

    var card = document.getElementById("face_card_" + face);

    if (card)
        {
        card.remove();
        }

    var titleEl = document.querySelector("#node_faces_box .Title");
    if (titleEl)
        {
        titleEl.textContent = titleEl.textContent.replace(/\d+(?=\s+faces$)/, function (match)
            {
            return Math.max(0, parseInt(match, 10) - 1);
            });
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