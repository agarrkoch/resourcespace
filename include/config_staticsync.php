<?php


#----

$nogo="[subfolder2] [metadata][Adam Finger] [Asian American Life] [Compression1] [Compression2] [Darren] [EBailey] [Final Cut Pro Documents] [Gisela] [Jiayi] [JoseLuis] [Julian] [Kalin] [Larry] [LisaBeth] [Marieve] [Mario] [Nueva York] [Octavio] [Ou] [SamS] [sarah] [Sylvester (XsanVideo)] [Theater BROLL (Press Reels)] [.TemporaryItems] [Theater Talk (XsanVideo)] [TimesTalks 2016] [Transperfect.Ep1101.EPS] [TEST] [.Trashes] [Wei] [Wilson] [Zhai] [.apdisk] [ZHAI_4-10.prproj] [.FCLM-UUID] [.Spotlight-V100] [xml.txt]"; # A list of folders to ignore within the sign folder.


# Should the generated resource title include the sync folder path?
$staticsync_title_includes_path=false;

# Optionally set this to ignore files that aren't at least this many seconds old
$staticsync_file_minimum_age = 120; 

/*
Allow the system to specify the exact folders under the sync directory that need to be synced/ingested in ResourceSpace.
Any subfolder that will match any of the folders in the $staticsync_whitelist_folders array will be synced.
Note: When using $staticsync_whitelist_folders and $nogo configs together, ResourceSpace is going to first check the
folder is in the $staticsync_whitelist_folders folders and then look in the $nogo folders.
*/

#### Edited by Aida G. Nov 12, 2025
// TElements studio folders
$cmd = "find '/Volumes/TElements/Studio' -mindepth 2 -type d | grep -Ev '/To_Archive/|/To_Retain/'";
$output = shell_exec($cmd);
$folders = array_filter(explode("\n", $output));
$telem_studio_folders = array_map(function($path) {
    return preg_replace('#^/Volumes/#', '', $path);
}, $folders);

// TElements camera card folders
$cmd = "find '/Volumes/TElements/Camera Card Delivery' -mindepth 2 -maxdepth 2 -type d | grep -Ev '/To_Remove/|/To_Retain/'";
$output = shell_exec($cmd);
$folders = array_filter(explode("\n", $output));
$telem_remote_folders = array_map(function($path) {
   return preg_replace('#^/Volumes/#', '', $path);
}, $folders);

// CUNYTVMEDIA Photo folders
// Tiger camera card folders
$cmd = "find '/Volumes/CUNYTVMEDIA/archive_projects/Photos' -mindepth 2 -maxdepth 2 -type d | grep -Ev '/To_Remove/|/To_Retain/'";
$output = shell_exec($cmd);
$folders = array_filter(explode("\n", $output));
$tvmedia_photo_folders = array_map(function($path) {
    return preg_replace('#^/Volumes/#', '', $path);
}, $folders);


$staticsync_whitelist_folders = array_merge($tvmedia_photo_folders, $telem_remote_folders, $telem_studio_folders);
#####

# added 2024-12-13 dave rice

# StaticSync Path to metadata mapping
# ------------------------
# It is possible to take path information and map selected parts of the path to metadata fields.
# For example, if you added a mapping for '/projects/' and specified that the second level should be 'extracted' means that 'ABC' would be extracted as metadata into the specified field if you added a file to '/projects/ABC/'
# Hence meaningful metadata can be specified by placing the resource files at suitable positions within the static
# folder heirarchy.
# Use the line below as an example. Repeat this for every mapping you wish to set up


#### Edited by Aida G. Nov 12, 2025	
#Studio
	# Asset Types
	$staticsync_mapfolders[]=array
		(
		"match"=>"/Studio/",
		"field"=>88,
		"level"=>2
		);
	# Series Title
	$staticsync_mapfolders[]=array
		(
		"match"=>"/Studio/",
		"field"=>89,
		"level"=>3
		);
	# Studio Types
	$staticsync_mapfolders[]=array
		(
		"match"=>"/Studio/",
		"field"=>90,
		"level"=>4
		);

# Camera Card Delivery
	# Asset Types
	$staticsync_mapfolders[]=array
		(
		"match"=>"/Camera Card Delivery/",
		"field"=>88,
		"level"=>2
		);
	# Series Title
	$staticsync_mapfolders[]=array
		(
		"match"=>"/Camera Card Delivery/",
		"field"=>89,
		"level"=>3
		);
		
# Photos
	# Asset Types
	$staticsync_mapfolders[]=array
		(
		"match"=>"/Photos/",
		"field"=>88,
		"level"=>5
		);
	# Studio Types
	$staticsync_mapfolders[]=array
		(
		"match"=>"/Photos/",
		"field"=>89,
		"level"=>4
		);

####
		
# Uncomment and set the next line to specify a category tree field to use to store the retieved path information for each file. The tree structure will be automatically modified as necessary to match the folder strucutre within the sync folder (performance penalty).
$staticsync_mapped_category_tree=91;
# Uncomment and set the next line to specify a text field to store the retrieved path information for each file. This is a time saving alternative to the option above.
$staticsync_filepath_to_field=92;



// Log developer debug information to the debug log (filestore/tmp/debug.txt)?  As the default location is world-readable it is recommended for production systems to change the location to somewhere outside of the web directory by also setting $debug_log_location.
$debug_log=true;
$show_error_messages = true;
	
// Optional extended debugging information from backtrace (records pagename and calling functions).
$debug_extended_info = false;

// Optional debug log location. Used to specify a full path to debug file and ensure folder permissions allow write access to both the file and the containing folder by web service account.
# $debug_log_location = "d:/logs/resourcespace.log";
$debug_log_location = "/var/log/resourcespace/resourcespace.log";


// added by Dave after edits to my.cnf
$filestore_cache = true;
$cache_dir = '/Users/libraryad/resourcespace_cache';
$preview_background_processing = true;
