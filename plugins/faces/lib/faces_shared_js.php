<?php
/**
 * Shared client-side logic for the faces plugin pages.
 *
 * Included inline inside each page's own <script> tag — NOT linked as a
 * static .js asset — because it embeds this request's own CSRF tokens and
 * language strings via PHP, the same way edit_fields/9.php gets included
 * inline per-face rather than being a static template.
 *
 * Expects $lang to be available (global, set by boot.php) and
 * generate_csrf_js_object() to be callable, same as the original inline
 * scripts this was extracted from.
 *
 * Provides DEFAULT implementations of:
 *   - ToggleNameEditor      (simple synchronous version. unnamed_faces.php
 *     overrides this with its AJAX lazy-fetch version — a function
 *     declared later in the same global scope replaces an earlier one,
 *     so a page's own <script> block, included after this one, can
 *     redeclare any of these to change behavior.)
 *   - updateFaceNameDisplay (unused by unnamed_faces.php, harmless there)
 *   - removeFaceCard        (cluster-aware remove + relabel/removal; safe
 *     to call on pages with no cluster boxes at all — closest() just
 *     returns null and this becomes a no-op beyond removing the card)
 *   - AutoSave              (simple version, no cluster propagation —
 *     collection_faces.php and unnamed_faces.php override this)
 *   - DeleteFace            (built on removeFaceCard — node_faces.php
 *     overrides this with its own flat, non-cluster version, since that
 *     page has no cluster boxes to relabel and instead decrements a
 *     single page-level count)
 * plus two helpers used identically everywhere, with no override needed:
 *   - FacesUpdateTag
 *   - extractKeywordChipName
 */
?>
// Tracks which face's name editor is currently open, since AutoSave() below
// is called generically by the included dynamic keywords field and needs to
// know which single face to save (these pages can span faces from many
// different resources, so we can't rebuild a whole resource's field value
// the way HookFacesViewCustompanels() does).
var currentEditingFace = null;

// Shared text for "unnamed" faces, used both to render it and to detect it.
var NO_NAME_TEXT = <?php echo json_encode($lang["faces-noname"] ?? "No name"); ?>;

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

// Removes a face card from the page, and updates (or removes) its
// containing cluster box if the page has one.
function removeFaceCard(face)
    {
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
    }

// Called by the included dynamic keywords field when a keyword is picked or
// cleared. Saves only the single face currently being edited.
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

    removeFaceCard(face);

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
