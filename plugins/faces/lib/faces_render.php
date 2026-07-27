<?php

/**
 * Shared face-rendering logic for the faces plugin.
 *
 * All three pages (node_faces.php, unnamed_faces.php, collection_faces.php)
 * independently computed the same bbox -> background-position/size math and
 * rendered near-identical face-card markup. find_face_preview() previously
 * only existed in unnamed_faces.php; node_faces.php and collection_faces.php
 * had a cruder inline version (jpg-only, no on-demand generation fallback).
 */

if (!function_exists('find_face_preview'))
    {
    // Finds a displayable preview for a resource, trying (in order): an
    // existing scr preview, the original file itself, then — only if
    // $try_generate is true — generating a scr preview on demand as a last
    // resort. Returns [path, url] or [null, null].
    //
    // $try_generate defaults to false because generating a preview on
    // demand has a real cost (ImageMagick/ffmpeg invocation, filestore
    // write) that's only worth paying on unnamed_faces.php, where a face
    // silently failing to render is otherwise much more likely to go
    // unnoticed at that page's scale. node_faces.php and collection_faces.php
    // pass false, matching their original (no-generation) behavior.
    //
    // Logs a specific, diagnosable reason when nothing works, so a gap
    // between "N faces" and what actually renders can be traced back to a
    // cause instead of just disappearing silently:
    //   - original file missing from the filestore entirely (nothing to
    //     generate a preview from), vs.
    //   - the original exists but generation failed (ImageMagick/ffmpeg
    //     config, or the web server user lacking write permission to the
    //     filestore).
    function find_face_preview($resource, $preview_extensions, $try_generate = false)
        {
        foreach ($preview_extensions as $ext)
            {
            $path = get_resource_path($resource, true, "scr", false, $ext);
            if (file_exists($path))
                {
                return [$path, get_resource_path($resource, false, "scr", false, $ext)];
                }
            }

        $original_exists = false;
        foreach ($preview_extensions as $ext)
            {
            $path = get_resource_path($resource, true, "", false, $ext);
            if (file_exists($path))
                {
                $original_exists = true;
                return [$path, get_resource_path($resource, false, "", false, $ext)];
                }
            }

        if ($try_generate)
            {
            $path = get_resource_path($resource, true, "scr", true, "jpg");
            if (file_exists($path))
                {
                return [$path, get_resource_path($resource, false, "scr", true, "jpg")];
                }
            }

        if (!$original_exists)
            {
            debug_log("Resource $resource: original file missing from filestore — cannot generate a preview.");
            }
        else
            {
            debug_log("Resource $resource: original file exists but scr preview generation failed (check ImageMagick/ffmpeg config and filestore write permissions).");
            }

        return [null, null];
        }
    }

if (!function_exists('compute_face_thumb_style'))
    {
    // Computes the inline style for a face's 120x120 thumbnail box: scales
    // so the ENTIRE bbox fits inside the box (letterboxed), instead of
    // scaling to fill it and cropping off part of the face, then centers
    // the scaled bbox within the box.
    //
    // Pass $face_url to embed background-image directly (node_faces.php,
    // collection_faces.php). Pass null to omit it (unnamed_faces.php's lazy
    // loading — the image URL goes in a data-face-src attribute instead,
    // set by JS once the card scrolls near the viewport).
    //
    // Returns the style string, or false if the face has no usable bbox
    // (caller should skip rendering this face, same as before).
    function compute_face_thumb_style($face, $image_width, $image_height, $face_url = null)
        {
        $bbox = json_decode($face["bbox"], true);

        if (!is_array($bbox))
            {
            debug_log("SKIP face {$face['ref']} (resource {$face['resource']}): invalid bbox data");
            return false;
            }

        list($x1, $y1, $x2, $y2) = $bbox;

        $face_width  = $x2 - $x1;
        $face_height = $y2 - $y1;

        if ($face_width <= 0 || $face_height <= 0)
            {
            debug_log("SKIP face {$face['ref']} (resource {$face['resource']}): non-positive bbox dimensions");
            return false;
            }

        $box_size = 120;
        $scale = min($box_size / $face_width, $box_size / $face_height);

        $crop_width  = $face_width * $scale;
        $crop_height = $face_height * $scale;

        $offset_x = ($box_size - $crop_width) / 2;
        $offset_y = ($box_size - $crop_height) / 2;

        $bg_pos_x = $offset_x - ($x1 * $scale);
        $bg_pos_y = $offset_y - ($y1 * $scale);

        $bg_size_x = $image_width * $scale;
        $bg_size_y = $image_height * $scale;

        if ($face_url !== null)
            {
            return sprintf(
                "background-image:url('%s'); background-position:%.1fpx %.1fpx; background-size:%.1fpx %.1fpx;",
                $face_url,
                $bg_pos_x,
                $bg_pos_y,
                $bg_size_x,
                $bg_size_y
            );
            }

        return sprintf(
            "background-position:%.1fpx %.1fpx; background-size:%.1fpx %.1fpx;",
            $bg_pos_x,
            $bg_pos_y,
            $bg_size_x,
            $bg_size_y
        );
        }
    }

if (!function_exists('render_face_card'))
    {
    /**
     * Renders one face card. $options:
     *   - 'style'                     (string, required) from compute_face_thumb_style()
     *   - 'lazy'                      (bool) true = data-face-src + empty
     *                                  editor placeholder (unnamed_faces.php);
     *                                  false = inline background-image +
     *                                  inline-rendered editor widget
     *                                  (node_faces.php, collection_faces.php)
     *   - 'face_url'                  (string) needed when lazy=true, for
     *                                  data-face-src
     *   - 'has_name'                  (bool)
     *   - 'display_name'              (string) shown when has_name is true
     *   - 'dynamic_keywords_available'(bool) — ignored when lazy=true, since
     *                                  the widget isn't rendered up front
     *                                  either way
     *   - 'faces_tag_field_data'      (array) — same, ignored when lazy=true
     *
     * Uses the global $baseurl and $lang, same as the original inline code.
     */
    function render_face_card($face, $options)
        {
        global $baseurl, $lang;

        $lazy = $options['lazy'] ?? false;
        $has_name = $options['has_name'] ?? false;
        $display_name = $options['display_name'] ?? '';
        $no_name_text = $lang["faces-noname"] ?? "No name";

        $search_url = generateURL(
            $baseurl . "/pages/search.php",
            ["search" => "!face" . $face["ref"]]
        );

        $view_url = generateURL(
            $baseurl . "/pages/view.php",
            ["ref" => $face["resource"]]
        );

        ob_start();
        ?>

        <div id="face_card_<?php echo $face["ref"]; ?>"
             data-resource="<?php echo (int)$face["resource"]; ?>"
             style="text-align:left; width:120px;">

            <a href="<?php echo $view_url; ?>"
               target="_blank"
               rel="noopener">
                <?php if ($lazy) : ?>
                <!-- data-face-src instead of an inline background-image: with
                     thousands of these on one page, loading every preview
                     image immediately overwhelms the browser. A lazy-load
                     observer only sets the actual background-image once a
                     card scrolls near the viewport. -->
                <div class="face-thumb" data-face-src="<?php echo escape($options['face_url']); ?>" style="<?php echo $options['style']; ?>"></div>
                <?php else : ?>
                <div class="face-thumb" style="<?php echo $options['style']; ?>"></div>
                <?php endif; ?>
            </a>

            <div>
                Face #<?php echo $face["ref"]; ?>
            </div>

            <div id="face_name_<?php echo $face["ref"]; ?>"
                 style="color: <?php echo $has_name ? 'green' : 'red'; ?>;">
                <?php echo $has_name ? escape($display_name) : escape($no_name_text); ?>
            </div>

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

            <?php if ($lazy) : ?>
            <!-- Intentionally empty. Pre-rendering the full dynamic-keywords
                 tag-picker widget here for EVERY face is what froze the
                 browser at scale (each copy embeds the entire named-people
                 list). ToggleNameEditor fetches this face's widget on
                 demand instead, the first time "Add name" is clicked. -->
            <div id="face_editor_<?php echo $face["ref"]; ?>"
                 class="face-name-editor"
                 data-loaded="0"
                 style="display:none; text-align:left; margin-top:6px;">
            </div>
            <?php else : ?>
            <div id="face_editor_<?php echo $face["ref"]; ?>"
                 class="face-name-editor"
                 style="display:none; text-align:left; margin-top:6px;">
                <?php
                $dynamic_keywords_available = $options['dynamic_keywords_available'] ?? false;
                if ($dynamic_keywords_available)
                    {
                    $field = $options['faces_tag_field_data'];
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
            <?php endif; ?>

        </div>

        <?php
        return ob_get_clean();
        }
    }
