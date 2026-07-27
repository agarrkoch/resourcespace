<?php

include '../../../include/boot.php';
include '../../../include/authenticate.php';

if (!checkperm('e0'))
    {
    exit($lang['error-permissiondenied']);
    }

require_once dirname(__FILE__, 2) . "/lib/faces_debug.php";
require_once dirname(__FILE__, 2) . "/lib/faces_render.php";

$ajax_editor_face = getval("ajax_editor_face", 0, true);

if ($ajax_editor_face > 0)
    {
    global $faces_tag_field;
    $faces_tag_field_data = get_resource_type_field($faces_tag_field);
    $dynamic_keywords_available = is_array($faces_tag_field_data)
        && $faces_tag_field_data["type"] == FIELD_TYPE_DYNAMIC_KEYWORDS_LIST;

    if ($dynamic_keywords_available)
        {
        global $pagename, $edit_autosave, $k;
        $pagename = "unnamed_faces";
        $edit_autosave = true;
        $k = 0;

        $faces_tag_field_data["field_constraint"] = $faces_tag_field_data["field_constraint"] ?? 0;
        $faces_tag_field_data["required"] = $faces_tag_field_data["required"] ?? 0;
        $faces_tag_field_data["automatic_nodes_ordering"] = $faces_tag_field_data["automatic_nodes_ordering"] ?? 0;

        $faces_tag_field_data["node_options"] = get_nodes($faces_tag_field_data["ref"], null, false);
        $field = $faces_tag_field_data;
        $name = "face_" . $ajax_editor_face;
        $selected_nodes = array(null); // every face here is unnamed by construction
        $multiple = false;
        $copyfrom = ""; // read only if $multiple is true, but declare it anyway to avoid any notice
        include dirname(__FILE__, 4) . '/pages/edit_fields/9.php';
        }
    else
        {
        echo escape($lang["faces-tag-field-not-set"] ?? "Tag field not configured");
        }

    exit;
    }

include_once "../../../include/header.php";

global $baseurl_short;
echo '<link rel="stylesheet" href="' . escape($baseurl_short) . 'plugins/faces/assets/faces.css">';

// This page's cluster computation can be genuinely heavy at scale (a large
// bulk FAISS call plus PHP-side corroboration passes over the results), so
// give it more room than the default script timeout rather than having it
// silently truncated mid-run. 0 = no limit; drop this to a real number if
// you'd rather it fail fast than hang.
set_time_limit(0);

$stage_timer_start = microtime(true);
function log_stage_time($label)
    {
    global $stage_timer_start;
    $elapsed = round(microtime(true) - $stage_timer_start, 2);
    debug_log("unnamed_faces.php TIMING: {$label} at +{$elapsed}s");
    }

/**
 * Unlike collection_clusters.php, a collection is OPTIONAL here — this page
 * is "every unnamed face", with an optional collection filter layered on
 * top (the faces_service API supports collection + untagged as independent,
 * stackable filters — see faces_service.py's build_face_query).
 */
$collection = getval("collection", 0, true);
$collection_data = false;

if ($collection > 0)
    {
    $collection_data = get_collection($collection);
    if ($collection_data === false)
        {
        exit("Collection not found.");
        }
    }

/**
 * Pagination — applied ONLY to which clusters get rendered on this
 * request. It has no effect on which faces are loaded or how they're
 * clustered: that still happens over the entire unnamed-faces set (see
 * the CLUSTERING section below), so a face on page 3 can still be linked
 * to a face that ends up rendered on page 1. Only the final, already-
 * computed $clusters array gets sliced, further down.
 */
$clusters_per_page = getval("clusters_per_page", 50, true);
if ($clusters_per_page < 1)
    {
    $clusters_per_page = 50;
    }

$page = getval("page", 1, true);
if ($page < 1)
    {
    $page = 1;
    }

global $baseurl_short, $baseurl;

echo '<div class="BasicsBox">';
echo '<h1><i class="plugin-icon fa-xl fa fa-user-tag" style="color:seagreen;"></i> Unnamed Faces</h1>';

if ($collection_data !== false)
    {
    $clear_filter_url = generateURL("{$baseurl_short}plugins/faces/pages/unnamed_faces.php", array());
    echo '<p>Filtered to collection: <strong>' . escape(i18n_get_collection_name($collection_data)) . '</strong>'
        . ' &nbsp;<a href="' . $clear_filter_url . '" onclick="return CentralSpaceLoad(this,true);">(show all unnamed faces)</a></p>';
    }

echo '<div style="border-bottom:1px solid #c1c1c1; box-shadow:0 12px 10px -12px #bbbbbb; margin:15px 0 20px 0;"></div>';

/**
 * If a collection filter is set, resolve it to a resource list up front so
 * the face query below can restrict to it. With no filter, resource_refs
 * stays null and the face query is unrestricted (all resources).
 */
$resource_refs = null;

if ($collection > 0)
    {
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
    }

/**
 * Load faces + embeddings — node IS NULL is the whole point of this page,
 * so it's unconditional (unlike collection_clusters.php, which shows a
 * collection's faces regardless of tag state).
 *
 * The WHERE clause is built once and reused for both the total-count query
 * and the paginated face query below, so the two can never disagree about
 * what's in scope.
 */
$where_sql = " WHERE rf.node IS NULL";
$base_params = [];

if ($resource_refs !== null)
    {
    $where_sql .= " AND rf.resource IN (" . implode(",", array_fill(0, count($resource_refs), "?")) . ")";
    foreach ($resource_refs as $r)
        {
        $base_params[] = "i";
        $base_params[] = $r;
        }
    }

$count_row = ps_query("SELECT COUNT(*) AS cnt FROM resource_face rf" . $where_sql, $base_params);
$total_unnamed = (int) ($count_row[0]["cnt"] ?? 0);

if ($total_unnamed > 0)
    {
    echo "<p>{$total_unnamed} unnamed faces.</p>";
    }

// No LIMIT/OFFSET here — clustering (below) needs to see every unnamed face
// in scope at once, otherwise two faces of the same person on different
// pages could never be linked (FAISS would find the match, but the old
// per-page filtering discarded it because the matched ref wasn't loaded on
// this page). Pagination is applied later, to CLUSTERS for rendering only,
// never to which faces are eligible to be clustered together.
$sql = "
SELECT
    rf.ref,
    rf.resource,
    rf.det_score,
    rf.bbox,
    rf.node,
    rf.vector_blob
FROM resource_face rf
" . $where_sql . "
ORDER BY rf.resource, rf.ref";

debug_log("unnamed_faces.php: loading all faces in scope, total_unnamed=$total_unnamed");

$faces = ps_query($sql, $base_params);
log_stage_time("face query complete, " . count($faces) . " rows");

debug_log("unnamed_faces.php: query returned " . count($faces) . " rows");

if (empty($faces))
    {
    echo "<p>No unnamed faces found.</p>";
    echo "</div>";
    include_once "../../../include/footer.php";
    exit;
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
 *
 * No "Phase 0" node-matching pass here (unlike collection_faces.php) —
 * every face on this page has node IS NULL by construction, so there's
 * nothing to group by tag. Clustering here is purely embedding-similarity
 * driven, and runs over the FULL set of in-scope faces regardless of
 * pagination (see pagination note further below, where $clusters gets
 * sliced only for rendering).
 */

require_once dirname(__FILE__, 2) . "/lib/faces_clustering.php";

$clusters = build_face_clusters(
    $faces,
    $collection,
    true,  // untagged - always true for this page
    30,    // bulk_k
    $faces_service_endpoint,
    $mysql_db,
    $faces_tag_threshold,
    false  // group_by_existing_node - N/A, every face here is node IS NULL
);

if ($clusters === false)
    {
    echo "<p>Error: could not reach faces_service for similarity lookup.</p>";
    echo "</div>";
    include_once "../../../include/footer.php";
    exit;
    }
log_stage_time("clustering complete, " . count($clusters) . " clusters from " . count($faces) . " faces");

/**
 * Pagination is applied here, AFTER clustering is fully complete — this is
 * the only place $clusters gets sliced. Clustering above always sees every
 * in-scope face regardless of $page/$clusters_per_page.
 */
$total_clusters = count($clusters);
$total_pages = max(1, (int) ceil($total_clusters / $clusters_per_page));

if ($page > $total_pages)
    {
    $page = $total_pages;
    }

$page_offset = ($page - 1) * $clusters_per_page;
$clusters_to_render = array_slice($clusters, $page_offset, $clusters_per_page);

log_stage_time(
    "pagination applied, page {$page}/{$total_pages}, rendering "
    . count($clusters_to_render) . " of {$total_clusters} clusters"
);

echo "<p>{$total_clusters} clusters from {$total_unnamed} unnamed faces";
if ($total_pages > 1)
    {
    echo " &nbsp;(showing clusters " . ($page_offset + 1) . "&ndash;"
        . min($page_offset + $clusters_per_page, $total_clusters)
        . ", page {$page} of {$total_pages})";
    }
echo "</p>";

?>

<?php

// Previews are usually jpg, but depending on server config can be stored
// under a different extension (e.g. png, to preserve transparency).
// Hardcoding "jpg" alone silently misses resources whose preview genuinely
// exists under a different extension.
$preview_extensions = ["jpg", "png", "gif"];

/**
 * Renders prev/next + numbered page links for the cluster listing.
 * Preserves the collection filter and a non-default clusters_per_page
 * across page links so paging doesn't silently drop either.
 */
function render_faces_pagination($page, $total_pages, $collection, $clusters_per_page)
    {
    if ($total_pages <= 1)
        {
        return;
        }

    global $baseurl_short;

    $base_params = [];
    if ($collection > 0)
        {
        $base_params["collection"] = $collection;
        }
    if ($clusters_per_page != 50)
        {
        $base_params["clusters_per_page"] = $clusters_per_page;
        }

    echo '<div class="FacesPagination" style="margin:14px 0; display:flex; gap:6px; align-items:center; flex-wrap:wrap;">';

    if ($page > 1)
        {
        $prev_url = generateURL("{$baseurl_short}plugins/faces/pages/unnamed_faces.php", $base_params + ["page" => $page - 1]);
        echo '<a href="' . $prev_url . '" onclick="return CentralSpaceLoad(this,true);">&laquo; Previous</a>';
        }

    $window = 3;
    $start = max(1, $page - $window);
    $end = min($total_pages, $page + $window);

    if ($start > 1)
        {
        $first_url = generateURL("{$baseurl_short}plugins/faces/pages/unnamed_faces.php", $base_params + ["page" => 1]);
        echo '<a href="' . $first_url . '" onclick="return CentralSpaceLoad(this,true);">1</a>';
        if ($start > 2)
            {
            echo '<span>&hellip;</span>';
            }
        }

    for ($p = $start; $p <= $end; $p++)
        {
        if ($p == $page)
            {
            echo '<strong style="padding:0 4px;">' . $p . '</strong>';
            }
        else
            {
            $p_url = generateURL("{$baseurl_short}plugins/faces/pages/unnamed_faces.php", $base_params + ["page" => $p]);
            echo '<a href="' . $p_url . '" onclick="return CentralSpaceLoad(this,true);">' . $p . '</a>';
            }
        }

    if ($end < $total_pages)
        {
        if ($end < $total_pages - 1)
            {
            echo '<span>&hellip;</span>';
            }
        $last_url = generateURL("{$baseurl_short}plugins/faces/pages/unnamed_faces.php", $base_params + ["page" => $total_pages]);
        echo '<a href="' . $last_url . '" onclick="return CentralSpaceLoad(this,true);">' . $total_pages . '</a>';
        }

    if ($page < $total_pages)
        {
        $next_url = generateURL("{$baseurl_short}plugins/faces/pages/unnamed_faces.php", $base_params + ["page" => $page + 1]);
        echo '<a href="' . $next_url . '" onclick="return CentralSpaceLoad(this,true);">Next &raquo;</a>';
        }

    echo '</div>';
    }

render_faces_pagination($page, $total_pages, $collection, $clusters_per_page);

// Starting from $page_offset (rather than 0) keeps "Cluster #N" labels
// globally consistent across pages — e.g. page 2 continues at #51 instead
// of restarting at #1 — since $clusters_to_render is just a slice of the
// full, already-numbered-by-position $clusters array.
$cluster_id = $page_offset;
$faces_skipped_no_image = 0;
$faces_skipped_bad_detection = 0;

foreach ($clusters_to_render as $cluster)
    {
    $cluster_id++;

    // content-visibility:auto tells the browser to skip layout/style/paint
    // work entirely for this box while it's off-screen, and do it lazily
    // as the user scrolls near it — this is the main remaining lever now
    // that images and the tag widget are already lazy: with potentially
    // thousands of cluster boxes on a page, laying out all of them upfront
    // is itself expensive regardless of what's inside each one.
    // contain-intrinsic-height gives the browser a rough placeholder
    // height (based on face count) so the page doesn't jump around as
    // boxes get measured for real once visible.
    $rows_estimate = max(1, (int) ceil(count($cluster["faces"]) / 8));
    $height_estimate = 70 + ($rows_estimate * 250);

    echo "<div class='RecordBox' id='cluster_box_" . $cluster_id . "' "
        . "style='content-visibility:auto; contain-intrinsic-height:" . $height_estimate . "px;'>";
    echo "<div class='RecordPanel'>";

    echo "<div class='Title'>";
    echo "Cluster #" . $cluster_id . " — " . count($cluster["faces"]) . " faces";
    echo "</div>";

    echo "<div class='Listview'>";
    echo "<div style='display:flex;flex-wrap:wrap;gap:36px 28px;padding:16px;'>";

foreach ($cluster["faces"] as $face)
        {
        [$face_path, $face_url] = find_face_preview($face["resource"], $preview_extensions, true);

        if ($face_path === null)
            {
            $faces_skipped_no_image++;
            continue;
            }

        list($image_width, $image_height) = getimagesize($face_path);

        // $face_url omitted (null) so compute_face_thumb_style() leaves
        // background-image out of the style entirely — this page sets it
        // lazily via data-face-src + an IntersectionObserver, further down.
        $style = compute_face_thumb_style($face, $image_width, $image_height, null);

        if ($style === false)
            {
            $faces_skipped_bad_detection++;
            continue;
            }

        echo render_face_card($face, [
            'style' => $style,
            'lazy' => true,
            'face_url' => $face_url,
            'has_name' => false,
            'display_name' => '',
        ]);
        }

    echo "</div>";
    echo "</div>";
    echo "</div>";
    echo "</div>";
    }

render_faces_pagination($page, $total_pages, $collection, $clusters_per_page);

if ($faces_skipped_no_image > 0 || $faces_skipped_bad_detection > 0)
    {
    echo '<p style="color:#a55;">';
    if ($faces_skipped_no_image > 0)
        {
        echo $faces_skipped_no_image . " face(s) couldn't be displayed (no preview image available). ";
        }
    if ($faces_skipped_bad_detection > 0)
        {
        echo $faces_skipped_bad_detection . " face(s) had invalid detection data.";
        }
    echo '</p>';
    }

?>

<script>
// With thousands of face cards on one page, setting every background-image
// immediately on load means the browser tries to fetch all of them at once —
// this is the actual slow part once clustering itself is fast. Instead, each
// .face-thumb only carries its image URL in data-face-src; this observer
// fills in the real background-image the first time a card scrolls near the
// viewport, then stops watching it. rootMargin gives a head start so images
// are ready slightly before they're actually visible, not popping in late.
if ('IntersectionObserver' in window)
    {
    var faceThumbObserver = new IntersectionObserver(function (entries, observer)
        {
        entries.forEach(function (entry)
            {
            if (!entry.isIntersecting)
                {
                return;
                }
            var el = entry.target;
            var src = el.getAttribute('data-face-src');
            if (src)
                {
                el.style.backgroundImage = "url('" + src + "')";
                el.removeAttribute('data-face-src');
                }
            observer.unobserve(el);
            });
        }, {rootMargin: '400px 0px'});

    document.querySelectorAll('.face-thumb[data-face-src]').forEach(function (el)
        {
        faceThumbObserver.observe(el);
        });
    }
else
    {
    // No IntersectionObserver support — fall back to loading everything
    // immediately rather than showing permanently blank thumbnails.
    document.querySelectorAll('.face-thumb[data-face-src]').forEach(function (el)
        {
        el.style.backgroundImage = "url('" + el.getAttribute('data-face-src') + "')";
        });
    }

<?php include dirname(__FILE__, 2) . "/lib/faces_shared_js.php"; ?>

// innerHTML does NOT execute <script> tags contained in the HTML you assign
// to it — that's standard browser behavior, not a bug in this page. The
// dynamic-keywords tag-picker widget almost certainly relies on an inline
// script to wire up its autocomplete/search behavior, so simply doing
// editor.innerHTML = html (as before) left the markup in place but silently
// never ran its initialization — which is exactly why tagging/keyword
// search stopped working once the widget started loading via AJAX. This
// re-creates each <script> tag so the browser actually executes it.
function injectHtmlAndRunScripts(container, html)
    {
    container.innerHTML = html;

    var oldScripts = container.querySelectorAll("script");
    oldScripts.forEach(function (oldScript)
        {
        try
            {
            var newScript = document.createElement("script");
            for (var i = 0; i < oldScript.attributes.length; i++)
                {
                var attr = oldScript.attributes[i];
                newScript.setAttribute(attr.name, attr.value);
                }
            newScript.textContent = oldScript.textContent;
            oldScript.parentNode.replaceChild(newScript, oldScript);
            }
        catch (e)
            {
            // Don't let one bad script silently break the whole editor —
            // and log exactly which script and what content failed, so the
            // actual cause is visible instead of a bare stack trace.
            console.error("injectHtmlAndRunScripts: script failed to execute:", e);
            console.error("Offending script content was:", oldScript.textContent);
            }
        });
    }

// Overrides the shared default from faces_shared_js.php: lazy-fetches this
// face's tag-picker widget via AJAX the first time "Add name" is clicked,
// instead of it being pre-rendered for every face up front.
function ToggleNameEditor(resource, face)
    {
    var editor = document.getElementById("face_editor_" + face);
    if (!editor)
        {
        return false;
        }

    var opening = (editor.style.display === "none" || editor.style.display === "");

    document.querySelectorAll(".face-name-editor").forEach(function (el)
        {
        if (el !== editor)
            {
            el.style.display = "none";
            }
        });

    if (opening && editor.getAttribute("data-loaded") !== "1")
        {
        editor.textContent = "Loading\u2026";
        editor.setAttribute("data-loaded", "loading");

        fetch("unnamed_faces.php?ajax_editor_face=" + encodeURIComponent(face)
            + "&ajax_editor_resource=" + encodeURIComponent(resource))
            .then(function (response) { return response.text(); })
            .then(function (html)
                {
                // TEMPORARY diagnostic — log the exact raw HTML the server
                // returned for this widget, so we can see what's actually
                // in it (not just infer from the error). Remove once the
                // underlying issue is found.
                console.log("EDITOR FRAGMENT (face " + face + "):", html);
                injectHtmlAndRunScripts(editor, html);
                editor.setAttribute("data-loaded", "1");
                })
            .catch(function ()
                {
                editor.textContent = "Failed to load name editor \u2014 please try again.";
                editor.setAttribute("data-loaded", "0");
                });
        }

    editor.style.display = opening ? "block" : "none";
    currentEditingFace = opening ? {resource: resource, face: face} : null;

    return false;
    }

// A face card fully disappears from this page once it's been named — it's
// no longer "unnamed", so it doesn't belong here anymore. This differs from
// collection_faces.php, where a newly-named face just updates its label
// in place and stays visible (that page shows the whole collection,
// tagged or not).
//
// When a face is given a name, apply that same name to every other face in
// the same cluster that's still unnamed — they're the same person, so
// naming one is a signal for the rest of the (still unnamed) group too.
// Each one is then removed from this page, same as the directly-tagged face.
function propagateNameToCluster(taggedFace, nodeId)
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
    var toRemove = [];
    cards.forEach(function (card)
        {
        var cardFace = card.id.replace("face_card_", "");
        if (cardFace === String(taggedFace))
            {
            return;
            }

        var cardResource = card.getAttribute("data-resource");
        FacesUpdateTag(cardResource, cardFace, nodeId);
        toRemove.push(cardFace);
        });

    toRemove.forEach(function (cardFace)
        {
        removeFaceCard(cardFace);
        });
    }

// Overrides the shared default from faces_shared_js.php: no
// updateFaceNameDisplay calls (cards vanish rather than being relabeled),
// plus cluster propagation and card removal.
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
        // Cleared with no name chosen — still unnamed, nothing to remove.
        FacesUpdateTag(resource, face, 0);
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
        FacesUpdateTag(resource, face, node[0]);
        propagateNameToCluster(face, node[0]);
        removeFaceCard(face);
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