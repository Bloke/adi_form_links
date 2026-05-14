<?php
$plugin['name'] = 'adi_form_links';
$plugin['version'] = '0.6.0';
$plugin['author'] = 'Adi Gilbert / Stef Dawson';
$plugin['author_uri'] = 'https://stefdawson.com/';
$plugin['description'] = 'Admin-side form link shortcuts';
$plugin['type'] = 3;
$plugin['flags'] = 0x0001 | 0x0002; // plugin options and lifecycle

# --- BEGIN PLUGIN TEXTPACK ---
$plugin['textpack'] = <<< EOT
#@owner gomedia
#@language en, en-gb, en-us
#@admin-side
adi_edit_form => Edit form
adi_forms_referenced => Forms referenced
adi_forms_used => Forms used
adi_link_list => Link list
adi_list_format => List format
adi_none_found => None found
adi_pref_update_fail => Preference update failed
adi_update_prefs => Update preferences
EOT;
# --- END PLUGIN TEXTPACK ---

if (!defined('txpinterface'))
	@include_once('zem_tpl.php');

if (0) {
?>
# --- BEGIN PLUGIN HELP ---

h1. *adi_form_links* - Admin-side form links

This plugin is an enhancement to the standard TXP Page and Form tabs designed to help speed up workflow.  It lists forms that are referenced within the current page or form. From an "idea by Edoardo":http://forum.textpattern.com/viewtopic.php?id=36961.

h2. *Usage*

After installing & activating the plugin, go to the Pages or Forms tab and you'll find a list of forms referenced by the current page or form.

The list can either be a simple list of links or a popup - choose your preference in the plugin options.

Forms are listed in the order they're found and their plugin tag is shown also.

Forms shown in grey haven't been found in the database.

Clicking or selecting a form in the list will take you to the Form Edit tab for that form. If the form doesn't exist then you'll be taken to a Form create tab.

# --- END PLUGIN HELP ---
<?php
}

// NOTE

# --- BEGIN PLUGIN CODE ---

/*
	adi_form_links - Admin-side form links

	Written by Adi Gilbert

	Released under the GNU General Public License

	Version history:
	0.5		- TXP 4.7+ only
	0.4		- refixes for TXP 4.6
	0.3.1	- fix: cope with default page not being called "default" in 4.6 (thanks jpdupont)
	0.3		- fixes: to back up the wild claim made about TXP 4.6 in version 0.2
			- code tidy up
	0.2		- ignore invalid form names
			- blocklist
			- tested on TXP 4.6
	0.1		- initial release (from an idea by Edoardo @wornout & thoroughly tested by Uli)

*/

//??? CODE

global $adi_form_links_debug, $adi_form_links_blocklist;

if (txpinterface == 'admin') {
	$adi_form_links_debug = 0;

	if (!version_compare(txp_version, '4.7', '>=')) return;

	// will look for attributes "*form", e.g.
	// 		TXP			- form, listform, searchform
	// 		adi_menu	- speaking_block_form
	// 		com_connect	- body_form, from_form, subject_form, thanks_form, to_form
	// and cater for false positives:
	$adi_form_links_blocklist[] = 'smd_wrap:transform';
	$adi_form_links_blocklist[] = 'smd_wrap_all:transform';
// 	$adi_form_links_blocklist[] = 'another_tag:another_attribute';

	// asynchronous action for Ajaxy save
	if (gps('adi_form_links_async')) {
		adi_form_links_load_lang('adi_forms_referenced');
		adi_form_links_markup();
		exit;
	}

	adi_form_links_init();
}

function adi_form_links_init() {
	global $event, $step, $prefs, $adi_form_links_debug, $adi_form_links_url, $adi_form_links_prefs, $adi_form_links_newname;

	// plugin lifecycle
	register_callback('adi_form_links_lifecycle', 'plugin_lifecycle.adi_form_links');

	// plugin options
	$adi_form_links_plugin_status = fetch('status', 'txp_plugin', 'name', 'adi_form_links', $adi_form_links_debug);
	if ($adi_form_links_plugin_status) { // proper install - options under Plugins tab
		add_privs('plugin_prefs.adi_form_links', '1,2,6');
		register_callback('adi_form_links_options', 'plugin_prefs.adi_form_links');
	}
	else { // txpdev - options under Extensions tab
		add_privs('adi_form_links_options', '1,2,6');
		register_tab('extensions', 'adi_form_links_options', 'adi_form_links options');
		register_callback('adi_form_links_options', 'adi_form_links_options');
	}

	// register adi_form_links_admin event (to stop adi_detritus complaining about prefs) - adi_form_links_admin() function currently not used
	register_callback('adi_form_links_admin', 'adi_form_links_admin');

	// get hold of language strings hidden behind event wall
	adi_form_links_load_lang('install_textpack, publisher, managing_editor, copy_editor, staff_writer, freelancer, designer, tag_popup');

	// preferences & defaults
	$adi_form_links_prefs = array(
		'adi_form_links_type' => array('value' => 'list', 'input' => 'radio'), // 'list' or 'popup'
	);
	foreach ($adi_form_links_prefs as $adi_form_links_pref => $this_pref) {
		$get_value = get_pref($adi_form_links_pref, '?'); // returns '?' if not set (but beware of cacheing)
		if ($get_value != '?')
			$adi_form_links_prefs[$adi_form_links_pref]['value'] = $get_value;
	}

	// some action
	if (($event == "page") || ($event == 'form')) { // 'ere we go
		// add markup
		register_callback('adi_form_links_markup', 'admin_side', 'footer'); // markup in the footer, shifted by jQuery
		// script
		register_callback('adi_form_links_script', 'admin_side', 'head_end');
		// grab new form name (grey link clicked in adi_form_links list)
		$adi_form_links_newname = gps('adi_form_links_newname');
		// style
		register_callback('adi_form_links_style', 'admin_side', 'head_end');
	}
}

function adi_form_links_load_lang($required_list) {
// load supplied comma-separated strings from txp_lang (for later use in gTxt())
	global $prefs, $adi_form_links_debug, $lang_ui;

	$extra_lang = array();

	$required = do_list($required_list); // convert comma-separated list to trimmed array

	if ($adi_form_links_debug) {
		echo __FUNCTION__.'():'.br;
		echo '$lang_ui='.$lang_ui.br;
		echo "REQUIRED LIST: $required_list".br;
	}

	if (isset($lang_ui)) {
		$where = "lang = '".doSlash($lang_ui)."' AND name != '' AND (name IN (".implode(',', quote_list($required))."))";
		$rs = safe_rows_start('name, data', 'txp_lang', $where);
		if (!empty($rs))
			while ($a = nextRow($rs))
				$extra_lang[$a['name']] = $a['data'];
		// feed extra strings into system
		Txp::get('\Textpattern\L10n\Lang')->setPack($extra_lang, TRUE);
	}
	else
		if ($adi_form_links_debug)
			echo '$lang_ui NOT SET'.br;

	if ($adi_form_links_debug) {
		echo 'EXTRA LANG:';
		dmp($extra_lang);
	}
}

function adi_form_links_style() {
// practical & stylish

	echo
		'<style>
			#adi_form_links { margin-top:1em }
			#adi_form_links h4 { margin:0 }
			#adi_form_links ul { list-style:none; margin:0; padding:0 0 1em }
			#adi_form_links select { margin-top:0.5em }
			#adi_form_links li.adi_form_links_new a, #adi_form_links option.adi_form_links_new { color:#777 }
		</style>';
}

function adi_form_links_lifecycle($event, $step) {
// from cradle to grave
	global $adi_form_links_debug, $adi_form_links_prefs;

	$result = '?';
	if ($step == 'enabled') {
		// save default pref values (needed for async operations)
		foreach ($adi_form_links_prefs as $this_pref => $pref_info)
			if (get_pref($this_pref) == '')
				$result = $result && set_pref($this_pref, $pref_info['value'], 'adi_form_links_admin', 2, $pref_info['input'], 0, FALSE);
	}
	if ($step == 'deleted') {
		// delete preferences
		foreach ($adi_form_links_prefs as $this_pref => $value)
			$result = $result && safe_delete('txp_prefs', "name = '$this_pref'", $adi_form_links_debug);
	}
	if ($adi_form_links_debug)
		echo "event=$event, step=$step, result=$result";
}

function adi_form_links_update_prefs() {
// trawl $_POST & update adi_form_links preferences
	global $adi_form_links_prefs;

	$res = TRUE;
	foreach ($adi_form_links_prefs as $adi_form_links_pref => $this_pref) {
		if (array_key_exists($adi_form_links_pref, $_POST))
			$new_value = $_POST[$adi_form_links_pref];
		else
			$new_value = '';
		$res = $res && set_pref($adi_form_links_pref, $new_value, 'adi_form_links_admin', 2, $adi_form_links_prefs[$adi_form_links_pref]['input'], 0, FALSE);
		$adi_form_links_prefs[$adi_form_links_pref]['value'] = $new_value;
	}

	return $res;
}

function adi_form_links_markup() {
// find the forms & generate the markup
	global $event, $step, $adi_form_links_debug, $adi_form_links_blocklist;

	$debug = __FUNCTION__.'():'.br;

	$name = gps('name');
	$newname = gps('newname'); // this may be supplied by async code on page/form save
	$debug .= "SUPPLIED: event=$event, step=$step, name=$name, newname=$newname".br;

	if ($event == 'page') {
		$last_saved = get_pref('last_page_saved');
		/*							$step		$name		$newname	form#page_form input[name="name"]
			Arrive from top menu:	-			-			-			abc					(last_saved or the "default" page is the one linked to section 'default')
			Arrive via edit link:	-			abc			-			abc
			Save existing page:		page_save	abc			-			new_abc				(ajax save - $step/$name/$newname don't change)
			Duplicate page:			page_save	abc			new_abc		new_abc 			(page refresh)
			New page:				page_new	-			-			-
			Save new page:			page_save	-			new_abc		new_abc 			(page refresh, also savenew=savenew)
			Delete page:			page_delete	abc			-
		*/
		if (empty($step) && empty($name)) // page tab visit from top menu (use last saved or "default")
			if (!($name = get_pref('last_page_saved'))) // get last saved or get page assigned to section "default"
				$name = safe_field('page', 'txp_section', "name='default'");
		if ($step == 'page_new') // nothing to show
			return '';
		if ($step == 'page_delete') // page delete, so default to default page
			$name = safe_field('page', 'txp_section', "name='default'"); // get page assigned to section "default"
		if ($newname != '') // page rename, duplicate or save new
			$name = $newname;
		// retrieve page contents from DB
		$row = safe_row('user_html', 'txp_page', " name='".$name."'");
		$data = ($row ? $row['user_html'] : '');
	}
	else if ($event == 'form') {
		$last_saved = get_pref('last_form_saved');
		/*							$step			$name		$newname	form#form_form input[name="name"]
			Arrive from top menu:	-				-			-			-				(last_saved or "default")
			Arrive via edit link:	form_edit		abc			-			abc
			Save existing form:		form_save		abc			-			new_abc			(ajax save - $step/$name/$newname don't change)
			Duplicate form:			form_save		abc			new_abc		new_abc 		(page refresh)
			New form:				form_create		-			-			-
			Save new form:			form_save		-			abc			abc				(page refresh, also savenew=savenew)
			Delete form:			form_multi_edit	-			-			-
		*/
		if (empty($step) && empty($name)) // page tab visit from top menu (use last saved or "default")
			if (!($name = get_pref('last_form_saved'))) // get last saved or default to "default"
				$name = 'default';
		if ($step == 'form_create') // nothing to show
			return '';
		if ($step == 'form_multi_edit') // form delete, so default to "default"
			$name = 'default';
		if ($newname != '') // form rename, duplicate or save new
			$name = $newname;
		// retrieve form contents from DB
		$row = safe_row('Form', 'txp_form', " name='".$name."'");
		$data = ($row ? $row['Form'] : '');
	}

	$debug.= "LAST SAVED: name=$last_saved".br;
	$debug.= "DEDUCED: name=$name".br;
	$debug .= 'BLOCKLIST: '.implode('; ', $adi_form_links_blocklist).br;

	// parse the page/form
	if (!defined('TXP_PATTERN'))
		define('TXP_PATTERN', get_pref('enable_short_tags', false) ? 'txp|[a-z]+:' : 'txp:?');
	$form_tags = adi_form_links_process_code($data, $debug);

	// remove duplicates & sort
	$form_tags = array_unique($form_tags);
	sort($form_tags);

	if (!$adi_form_links_debug) $debug = '';

	// generate markup
	if ($form_tags) {
		$lis = $selects = array();
		$skin = get_pref('skin_editing', 'default');
		foreach ($form_tags as $attr_name_pair) {
			list($form_name, $tag_name) = explode(':', $attr_name_pair); // extract 'form_name:tag_name' into vars
			if (safe_row('name', 'txp_form', " name='".doSlash($form_name)."' AND skin='".doSlash($skin)."'")) { // form exists
				$class = '';
				$elink_step = 'form_edit';
				$elink_thing = 'name';
			}
			else {
				$class = ' class="adi_form_links_new"';
				$elink_step = 'form_create';
				$elink_thing = 'adi_form_links_newname';
			}
			$lis[] = tag(elink('form', $elink_step, $elink_thing, $form_name, $form_name).' ('.$tag_name.')', 'li', $class);
			$selects[] = '<option'.$class.' value="'.$form_name.'">'.$form_name.' ('.$tag_name.')</option>';
		}
		$lis = tag(implode($lis), 'ul');
		if (get_pref('adi_form_links_type') == 'list') // list of links
			$markup = hed(gTxt('adi_forms_referenced'), 4).$lis;
		else // popup
			$markup =
				form(
					tag(strong(gTxt('adi_forms_referenced')), 'label', ' for="adi_form_links_forms"')
					.br
					.tag('<option value="">&nbsp;</option>'.implode('', $selects), 'select', ' id="adi_form_links_forms" name="name"')
					.fInput('submit', 'edit_form', gTxt('adi_edit_form'), 'smallerbox')
					.eInput('form')
					.sInput('form_edit')
				);
	}
	else
		$markup = hed(gTxt('adi_forms_referenced'), 4).gTxt('adi_none_found');

	echo tag(
		$debug
		.$markup
		, 'div'
		, ' id="adi_form_links"'
	);

}

function adi_form_links_process_code($code, &$debug) {
// recursively process page/form code, returns form name/tag array
	global $adi_form_links_blocklist;

	$form_tags = array();

	$parsed = adi_form_links_parse($code);

	if (is_array($parsed)) { // TXP code found
		foreach ($parsed as $portion) {
			foreach ($portion as $morsel) {
				if (is_array($morsel)) { // tag (+ possibly more TXP code) found
					// [0] = full tag code
					// [1] = tag name
					// [2] = attributes
					// [3] = more (possibly unparsed) TXP code or NULL
					// [4] = closing tag or NULL
					$tag = $morsel[1];
					$attrs = $morsel[2];
					$debug .= "TAG=$tag, ATTRS=[".htmlspecialchars($attrs).']'.br;
					// look for form-like attributes
					if (preg_match_all("/(\S*form)\s*=\s*\"(.+)\"/iUs", $attrs, $matches)) {
						foreach ($matches[1] as $index => $attr) {
							$form_name = trim($matches[2][$index]);
							$debug .= "- FORM ATTR=$attr, FORM NAME=".htmlspecialchars($form_name);
							if (in_array(strtolower("$tag:$attr"), $adi_form_links_blocklist)) // case insensitive - just in Case!
								$debug .= ' ***BLOCKLISTED***';
							else if ($form_name == '')
								$debug .= ' ***IGNORED***';
							else
								$form_tags[] = "$form_name:$tag"; // form attribute value (i.e. form name) & tag name - e.g. html_tail:output_form
							$debug .= br;
						}
					}

					$form_tags = array_merge($form_tags, adi_form_links_process_code(($morsel[3] === null ? '' : $morsel[3]), $debug));
				}
			}
		}
	}

	return $form_tags;
}

function adi_form_links_parse($thing, $condition = true, $not = true) {
// parses page/form code
// 	- direct copy of parse() from textpattern/lib/txplib_publish.php (TXP 4.7)
// TXP CODE ABUSE:
//	- stripped out some bits
//	- returning $tags instead of $out

    global $pretext, $production_status, $trace, $txp_parsed, $txp_else, $txp_atts, $txp_tag;
    static $short_tags = null;

    if ($not && !empty($txp_atts['not'])) {
        $condition = empty($condition);
        unset($txp_atts['not']);
    }

//     if ($production_status === 'debug') {
//         $trace->log('['.($condition ? 'true' : 'false').']');
//     }

    if (!$condition && empty($pretext['_txp_atts'])) {
        $txp_atts = null;
    }

    if (!isset($short_tags)) {
        $short_tags = get_pref('enable_short_tags', false);
    }

    if (!$short_tags && false === strpos($thing, '<txp:') ||
        $short_tags && !preg_match('@<(?:'.TXP_PATTERN.'):@', $thing))
    {
        return $condition ? ($thing === null ? '1' : $thing) : '';
    }

    $hash = sha1($thing);

//     if (!isset($txp_parsed[$hash])) {
        $tag     = array();
        $outside = array();
        $else    = array(-1);
        $count   = array(-1);
        $level   = 0;

        $f = '@(</?(?:'.TXP_PATTERN.'):\w+(?:\s+[\w\-]+(?:\s*=\s*(?:"(?:[^"]|"")*"|\'(?:[^\']|\'\')*\'|[^\s\'"/>]+))?)*\s*/?\>)@s';
        $t = '@^</?('.TXP_PATTERN.'):(\w+)(.*)/?\>$@s';

        $parsed = preg_split($f, $thing, -1, PREG_SPLIT_DELIM_CAPTURE);
        $last = count($parsed);
        $inside  = array($parsed[0]);
        $tags    = array($inside);

        for ($i = 1; $i < $last || $level > 0; $i++) {
            $chunk = $i < $last ? $parsed[$i] : '</txp:'.$tag[$level-1][2].'>';
            preg_match($t, $chunk, $tag[$level]);
            $count[$level] += 2;

            if ($tag[$level][2] === 'else') {
                $else[$level] = $count[$level];
            } elseif ($tag[$level][1] === 'txp:') {
                // Handle <txp::shortcode />.
                $tag[$level][3] .= ' form="'.$tag[$level][2].'"';
                $tag[$level][2] = 'output_form';
            } elseif ($short_tags && $tag[$level][1] !== 'txp') {
                // Handle <short::tags />.
                $tag[$level][2] = rtrim($tag[$level][1], ':').'_'.$tag[$level][2];
            }

            if ($chunk[strlen($chunk) - 2] === '/') {
                // Self closed tag.
//                 if ($chunk[1] === '/') {
//                     trigger_error(gTxt('ambiguous_tag_format', array('{chunk}' => $chunk)), E_USER_WARNING);
//                 }

                $tags[$level][] = array($chunk, $tag[$level][2], trim($tag[$level][3]), null, null);
                $inside[$level] .= $chunk;
            } elseif ($chunk[1] !== '/') {
                // Opening tag.
                $inside[$level] .= $chunk;
                $level++;
                $outside[$level] = $chunk;
                $inside[$level] = '';
                $else[$level] = $count[$level] = -1;
                $tags[$level] = array();
            } else {
                // Closing tag.
                if ($level < 1) {
//                     trigger_error(gTxt('missing_open_tag', array('{chunk}' => $chunk)), E_USER_WARNING);
                    $tags[$level][] = array($chunk, null, '', null, null);
                    $inside[$level] .= $chunk;
                }
                else {
//                     if ($i >= $last) {
//                         trigger_error(gTxt('missing_close_tag', array('{chunk}' => $outside[$level])), E_USER_WARNING);
//                     } elseif ($tag[$level-1][2] != $tag[$level][2]) {
//                         trigger_error(gTxt('mismatch_open_close_tag', array(
//                             '{from}' => $outside[$level],
//                             '{to}'   => $chunk,
//                         )), E_USER_WARNING);
//                     }

                    $sha = sha1($inside[$level]);
                    $txp_parsed[$sha] = $count[$level] > 2 ? $tags[$level] : false;
                    $txp_else[$sha] = array($else[$level] > 0 ? $else[$level] : $count[$level], $count[$level] - 2);
                    $level--;
                    $tags[$level][] = array($outside[$level+1], $tag[$level][2], trim($tag[$level][3]), $inside[$level+1], $chunk);
                    $inside[$level] .= $inside[$level+1].$chunk;
                }
            }

            $chunk = ++$i < $last ? $parsed[$i] : '';
            $tags[$level][] = $chunk;
            $inside[$level] .= $chunk;
        }

        $txp_parsed[$hash] = $tags[0];
        $txp_else[$hash] = array($else[0] > 0 ? $else[0] : $count[0] + 2, $count[0]);
//     }

//     $tag = $txp_parsed[$hash];
//
//     if (empty($tag)) {
//         return $condition ? $thing : '';
//     }
//
//     list($first, $last) = $txp_else[$hash];
//
//     if ($condition) {
//         $last = $first - 2;
//         $first   = 1;
//     } elseif ($first <= $last) {
//         $first  += 2;
//     } else {
//         return '';
//     }
//
//     for ($out = $tag[$first - 1]; $first <= $last; $first++) {
//         $txp_tag = $tag[$first];
//         $out .= processTags($txp_tag[1], $txp_tag[2], $txp_tag[3]).$tag[++$first];
//     }
//
//     $txp_tag = null;
//
//     return $out;

	return $tags;
}

function adi_form_links_script() {
// jQuery-ism
	global $event, $step, $adi_form_links_debug, $adi_form_links_newname;

	if ($adi_form_links_debug)
		echo "adi_form_links_newname=$adi_form_links_newname";

	if ($adi_form_links_newname)
		$populate_newname_code = <<<END_SCRIPT
		// populate new name in blank input field
		$("input#new_form").val("$adi_form_links_newname");
END_SCRIPT;
	else
		$populate_newname_code = '';

	// markup shifty & replenish
	$form_id = $event."_form"; // i.e. "page_form" or "form_form"
	echo <<<END_SCRIPT
<script>
	$(function() {
		// adi_form_links
		// shift form links markup from footer
		$("div#adi_form_links").appendTo($("#main_content"));
		$populate_newname_code
		// set up for async replenish
		event = "$event";
		step = "$step";
		window_url = window.location.href;
		debug = $adi_form_links_debug;
		textpattern.Relay.register('txpAsyncForm.success', function() {
			// replenish markup
			if (debug) console.log('adi_form_links: event=' + event + ', step=' + step + ', window_url=' + window_url);
			newname = $('form#$form_id input[name="name"]').val();
			newname_enc = encodeURIComponent(newname).replaceAll('%20', '+'); // convert spaces to '+'
			async_url = window.location.href;
			if ((step == 'page_save') || (step == 'form_save')) { // event & step need to re-added to url after duplicate or create
				if (debug) console.log('adi_form_links: add event (' + event + ') & step (' + step + ') to url');
				async_url = async_url + '?event=' + event + '&step=' + step;
			}
			async_url = async_url + '&adi_form_links_async=1' + '&newname=' + newname_enc;
			if (debug) {
				console.log('adi_form_links: newname=' + newname + ', newname_enc=' + newname_enc);
				console.log('adi_form_links: async_url=' + async_url);
			}
			// refresh form links on Ajaxy save
			$('#adi_form_links').load(async_url);
		});
	});
</script>
END_SCRIPT;
}

function adi_form_links_options($event, $step) {
// plugin options page
	global $adi_form_links_url, $adi_form_links_prefs, $lang_ui;

	$message = '';

	// step-tastic
	if ($step == 'update_prefs') {
		$result = adi_form_links_update_prefs();
		$result ? $message = gTxt('preferences_saved') : $message = gTxt('adi_pref_update_fail');
	}

	// generate page
	if (!empty($message))
		$message = '<strong>'.$message.'</strong>';

	pagetop('adi_form_links '.gTxt('options'), $message);

	// options
	echo tag(
		tag('adi_form_links '.gTxt('options'), 'h2')
 		// preferences
 	  	.form(
			tag(gTxt('edit_preferences'), 'h3')
			.graf(
				tag(gTxt('adi_list_format'), 'label', ' for="adi_form_links_type"')
				.sp.sp
				.tag(
					radio('adi_form_links_type', 'list', ($adi_form_links_prefs['adi_form_links_type']['value'] == 'list'))
					.sp
					.gTxt('adi_link_list')
					, 'label'
				)
				.sp.sp
				.tag(
					radio('adi_form_links_type', 'popup', ($adi_form_links_prefs['adi_form_links_type']['value'] == 'popup'))
					.sp
					.gTxt('tag_popup')
					, 'label'
				)
			)
			.graf(fInput('submit', 'do_something', gTxt('adi_update_prefs'), 'smallerbox'))
			.eInput($event)
			.sInput('update_prefs')
		)
		, 'div'
		, ' style="text-align:center; margin-bottom:3em"'
	);
}

# --- END PLUGIN CODE ---
?>
