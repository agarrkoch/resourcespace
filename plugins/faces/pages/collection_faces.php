<?php

include '../../../include/boot.php';
include '../../../include/authenticate.php';

if (!checkperm('a'))
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
    exit("No resources in collection.");
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
    exit("No faces found in collection.");
}

/**
 * -----------------------------
 * Helpers
 * -----------------------------
 */

function cosine_similarity($a, $b)
{
    $dot = 0;
    $normA = 0;
    $normB = 0;

    $len = min(count($a), count($b));

    for ($i = 0; $i < $len; $i++)
    {
        $dot += $a[$i] * $b[$i];
        $normA += $a[$i] * $a[$i];
        $normB += $b[$i] * $b[$i];
    }

    if ($normA == 0 || $normB == 0) return 0;

    return $dot / (sqrt($normA) * sqrt($normB));
}

function vector_average($a, $b)
{
    $out = [];

    $len = min(count($a), count($b));

    for ($i = 0; $i < $len; $i++)
    {
        $out[$i] = ($a[$i] + $b[$i]) / 2;
    }

    return $out;
}

function normalize_vector($v)
{
    $norm = 0;

    foreach ($v as $x)
    {
        $norm += $x * $x;
    }

    $norm = sqrt($norm);

    if ($norm == 0) return $v;

    foreach ($v as &$x)
    {
        $x /= $norm;
    }

    return $v;
}

function unpack_vector_blob($blob)
{
    if ($blob === null || $blob === '')
    {
        return null;
    }

    // If it's hex (0x or pure hex string)
    if (is_string($blob) && preg_match('/^[0-9a-fA-F]+$/', preg_replace('/^0x/i', '', $blob)))
    {
        $blob = preg_replace('/^0x/i', '', $blob);
        $bin = hex2bin($blob);

        if ($bin === false)
        {
            return null;
        }
    }
    else
    {
        // already binary
        $bin = $blob;
    }

    $arr = unpack("f*", $bin);

    if ($arr === false)
    {
        return null;
    }

    return array_values($arr);
}

/**
 * -----------------------------
 * CLUSTERING
 * -----------------------------
 */

$threshold = 0.65;

$clusters = [];

foreach ($faces as $face)
{
	$vec = unpack_vector_blob($face["vector_blob"]);

	if (!is_array($vec) || count($vec) < 10)
	{
	    continue;
	}

    $assigned = false;

    foreach ($clusters as &$cluster)
    {
        $sim = cosine_similarity($vec, $cluster["centroid"]);

        if ($sim >= $threshold)
        {
            $cluster["faces"][] = $face;
			$cluster["centroid"] = normalize_vector(
			    vector_average($cluster["centroid"], $vec)
			);
            $assigned = true;
            break;
        }
    }

    unset($cluster);

    if (!$assigned)
    {
        $clusters[] = [
            "centroid" => $vec,
            "faces" => [$face]
        ];
    }
}

?>

<div class="BasicsBox">

<h1>Face Clusters</h1>

<p>
Collection:
<strong><?php echo escape($collection_data["name"]); ?></strong>
</p>

<?php

$cluster_id = 0;

foreach ($clusters as $cluster)
{
    $cluster_id++;

    echo "<div class='RecordBox'>";
    echo "<div class='RecordPanel'>";

    echo "<div class='Title'>";
    echo "Cluster #" . $cluster_id . " — " . count($cluster["faces"]) . " faces";
    echo "</div>";

    echo "<div class='Listview'>";
    echo "<div style='display:flex;flex-wrap:wrap;gap:20px;'>";

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

        $scale = 120 / $face_width;

        $bg_pos_x = -$x1 * $scale;
        $bg_pos_y = -$y1 * $scale;

        $bg_size_x = $image_width * $scale;
        $bg_size_y = $image_height * $scale;

        $style = sprintf(
            "width:120px;height:120px;
            background-image:url('%s');
            background-position:%dpx %dpx;
            background-size:%dpx %dpx;
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

        <div style="text-align:center; width:120px;">

            <a href="<?php echo $view_url; ?>"
               onclick="return ModalLoad(this,true);">
                <div style="<?php echo $style ?>"></div>
            </a>

            <div>
                Face #<?php echo $face["ref"]; ?>
            </div>

            <div>
                <?php echo round($face["det_score"] * 100, 1); ?>%
            </div>

            <div>
                <a href="<?php echo $search_url; ?>"
                   onclick="return CentralSpaceLoad(this,true);">
                    Find
                </a>
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

</div>

<?php include_once "../../../include/footer.php"; ?>