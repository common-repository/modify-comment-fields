<?php
/*
 * Plugin Name:		Comment Fields [Modify/Disable/Remove]
 * Description:		Prevent users from modifying specific profile & dashboard fields.
 * Text Domain:		modify-comment-fields
 * Domain Path:		/languages
 * Version:		1.08
 * WordPress URI:	https://wordpress.org/plugins/modify-comment-fields/
 * Plugin URI:		https://puvox.software/software/wordpress-plugins/?plugin=modify-comment-fields
 * Contributors: 	puvoxsoftware,ttodua
 * Author:		Puvox.software
 * Author URI:		https://puvox.software/
 * Donate Link:		https://paypal.me/Puvox
 * License:		GPL-3.0
 * License URI:		https://www.gnu.org/licenses/gpl-3.0.html
 
 * @copyright:		Puvox.software
*/


namespace ModifyCommentFields
{
	if (!defined('ABSPATH')) exit;
	require_once( __DIR__."/library.php" );
	require_once( __DIR__."/library_wp.php" );
	
	class PluginClass extends \Puvox\wp_plugin
	{
  
	  public function declare_settings()
	  {
		  $this->initial_static_options	= 
		  [
			  'has_pro_version'        => 0, 
			  'show_opts'              => true, 
			  'show_rating_message'    => true, 
			  'show_donation_footer'   => true, 
			  'show_donation_popup'    => true, 
			  'menu_pages'             => [
				  'first' =>[
					  'title'           => 'Modify Comments fields', 
					  'default_managed' => 'network',            // network | singlesite
					  'required_role'   => 'install_plugins',
					  'level'           => 'submenu', 
					  'page_title'      => 'Comment Fields [Modify/Disable/Remove]',
					  'tabs'            => [],
				  ],
			  ]
		  ];

		$this->initial_user_options	= 
		[
			'enable_url'		=> true,
			'enable_email'		=> true,
			//
			'custom_note'	 	=>"(Note, links and most HTML attributes are not allowed in comments)", 
			'custom_note_css'	=>"color:black; font-size:0.8em; background-color:#e7e7e7; padding:4px; margin:4px; font-style:italic;",
			'enable_attributes_in_comment'=> true,
			'enable_attributes_msg'=> "Links and most HTML attributes are not accepted.",
			'enable_comments_all'=> true,
			//
			'captcha_mode'	=>	'none',	//none, simple, recaptcha
			'enable_captcha_for_logged_in'=> false,	//none, simple, recaptcha
			'google_captcha_public'=>'',
			'google_captcha_secret'=>'',
		];
	} 
	
	
	public function PowerUser()
	{
		return current_user_can('manage_options');
	}
	
	// Note1: Each "comment" object has: 'comment_..' post_ID, author, author_email, author_url, content, type, parent, 'user_ID' 
	// Note2: "get_comment" hook is also applied in get_comments

	public function __construct_my()
	{


		// ########## Commenter URL ##########
		if ( ! $this->opts['enable_url'])
		{
			// FRONT-END
			$website_remove=function ($fields)
			{
				if(isset($fields['url']))
					unset($fields['url']);
				return $fields;
			};
			add_filter('comment_form_default_fields', $website_remove);
			
			// BACK-END
			$website_remove=function ($fields)
			{
				//if(isset($fields['comment_author_url']))
					$fields['comment_author_url']='';	//unset causes errors, so emptify it
				return $fields;
			};
			add_filter('preprocess_comment', $website_remove);
			
			// CURRENT DATA
			$website_remove=function ($comment){
				$comment->comment_author_url="";		//unset causes errors, so emptify it
				return $comment;
			};
			add_filter( 'get_comment', $website_remove );
		}
		// #####################################



		// ########## Commenter EMAIL ##########
		if ( ! $this->opts['enable_email'] )
		{
			// FRONT-END
			$func=function ($fields)
			{
				if(isset($fields['email']))
					unset($fields['email']);
				return $fields;
			};
			add_filter('comment_form_default_fields', $func);
			
			// BACK-END
			$func=function ($fields)
			{ 
				//if(isset($fields['comment_author_email']))
					$fields['comment_author_email']="";	//unset causes errors, so emptify it
				return $fields;
			};
			add_filter('preprocess_comment', $func);  //in wp_new_comment
			
					//additionally, we need to disable WP builtin requirement of NAME & MAIL through the pre-processing
					$this->reqMailKey='pre_option_'.'require_name_email';
					if (get_option($this->reqMailKey))
					{
						// anything but not false! then reset back
						add_filter($this->reqMailKey, "__return_zero");
						add_filter('preprocess_comment', function($cData) { add_filter($this->reqMailKey, "__return_false"); return $cData; } );
					}

			// CURRENT DATA
			$func=function ($comment){
				$comment->comment_author_email="";		//unset causes errors, so emptify it
				return $comment;
			};
			add_filter( 'get_comment', $func );
		}
		// ##########################################



		// ##########  Remove HTML in Comments ##########
		if ( ! $this->opts['enable_attributes_in_comment'] )
		{
			$this->pattern="/<([a-z][a-z0-9]*)[^>]*?(\/?)>/si"; //<$1$2>
			
			// FRONT-END
			$func2=function ($field){ 
 
				$script = 
				'<script>'.
				'(function(){'. 
					'function mcf_comment_check(event){ var d=document.createElement("div"); d.innerHTML=event.target.value; var els=d.getElementsByTagName("*"); for (var i=0; i<els.length; i++) { if (els[i].attributes.length!=0) { alert("'.__($this->opts['enable_attributes_msg']).'"); while(els[i].attributes.length > 0) els[i].removeAttribute(els[i].attributes[0].name); event.target.value = d.innerHTML; }  } }'.
					'document.currentScript.parentNode.getElementsByTagName("textarea")[0].addEventListener("input", mcf_comment_check);'.
				'})();'.
				'</script>';
				$field=str_replace('</textarea>','</textarea>'.$script, $field); 
				return $field;
			};
			add_filter( 'comment_form_field_comment', $func2 );

			// BACK-END
			$func=function($approved, $commentdata)
			{
				if(isset($commentdata['comment_content']))
				{
					// better to check against DOM (php-xml should be available on most installations)
					// https://stackoverflow.com/a/3026411/2377343
					if(class_exists('DOMDocument'))
					{
						$dom = new \DOMDocument('1.0', 'UTF-8');
						$internalErrors = libxml_use_internal_errors(true);	//disable
						$dom->loadHTML( $commentdata['comment_content']);
						libxml_use_internal_errors($internalErrors);		//restore
						$finder = new \DOMXpath( $dom );
						$nodes= $finder->query( "//*" );
						foreach ($nodes as $node) {
							if ($node->hasAttributes())
							{
								$error = $node->ownerDocument->saveHTML($node); break;
							}
						}
					}
					else
					{
						preg_match_all($this->pattern, $text, $new);
						$tags = implode(",",$new[0]);
						if ( stripos($tags,' ')!==false || stripos($tags,'=') !== false )
						{
							$error = $tags;
						}
					}
					if (isset($error))
						return new \WP_Error( 'comment_contains_html_attributes', __($this->opts['enable_attributes_msg']) . " (". esc_html($error) .")", 200);
				}
				return $approved;
			};
			add_filter('pre_comment_approved', $func, 50, 2);
			//preprocess_comment -> wp_filter_comment(pre_comment_content) -> wp_allow_comment(pre_comment_approved)
			// old: $tags = $GLOBALS['allowedtags']; unset($tags['a']); $content = addslashes(wp_kses(stripslashes($content), $tags)); return $content;

			// CURRENT DATA
			remove_filter('comment_text', 'make_clickable', 5);
			$func=function ($comment){
				if(!empty($comment))
					$comment->comment_content = preg_replace($this->pattern, "<$1$2>", $comment->comment_content);
				return $comment;
			};
			add_filter( 'get_comment', $func );	//better, because 'comment_text' still bypasses author(!)
			
			

			// ## Custom Note ##
			if( ! empty($this->opts['custom_note']))
			{
				$func = function($defaults)
				{
					$defaults['comment_notes_after'] .= '<div class="custommessage">'. htmlentities($this->opts['custom_note']).'</div><style>.custommessage{'.sanitize_text_field($this->opts['custom_note_css']).'}</style>';
					return $defaults;
				}; 
				add_filter( 'comment_form_defaults', $func , 50);
			}
		}
		// #####################################
		


		// ########## Disable comments ##########
		if ( ! $this->opts['enable_comments_all'] )
		{
			$func = function () {
				// Remove comments metabox from dashboard
				//remove_meta_box('dashboard_recent_comments', 'dashboard', 'normal');
				
				// Disable support for comments and trackbacks in post types
				foreach (get_post_types() as $post_type) {
					if (post_type_supports($post_type, 'comments')) {
						remove_post_type_support($post_type, 'comments');
						remove_post_type_support($post_type, 'trackbacks');
					}
				}
				add_filter('comments_open', '__return_false', 20, 2);		// close
				add_filter('pings_open', 	'__return_false', 20, 2);		// close
				add_filter('comments_array','__return_empty_array', 10, 2);	// Hide existing comments

			};
			add_action('init', $func);
		}
		// ##########################################
		
		
		
		


		// ##########  Captcha  ##########
		if ( $this->opts['captcha_mode']!="none" )
		{
			// FRONT-END
			$func=function ($submit_button)
			{
				if ($this->opts['captcha_mode']=="simple")
				{	$id = get_the_ID();
					$out = __('<div >AntiSpam checkbox: <input type="checkbox" name="rcf_antispam" value="'. ( get_the_date('d', $id) * pow($id, 2)*get_option('rcf_sample_random') ).'" /></div>');
				}
				elseif ($this->opts['captcha_mode']=="recaptcha")
				{  
					$out = (empty($this->opts['google_captcha_public'])) ? '<b>ReCaptcha key is empty!</b>' : '<script src="https://www.google.com/recaptcha/api.js?render='.$this->opts['google_captcha_public'].'"></script><input type="hidden" name="recaptcha_response" id="recaptchaResponse"><input type="hidden" id="g-recaptcha-response" name="g-recaptcha-response"><input type="hidden" name="action" value="rcf_validate_captcha">
					<script>grecaptcha.ready(function() { grecaptcha.execute("'.$this->opts['google_captcha_public'].'", {action:"rcf_validate_captcha"}).then(function(token) { document.getElementById("g-recaptcha-response").value = token; });  });</script><style>.grecaptcha-badge{ visibility: collapse !important; }</style>';
				}
				return $out.$submit_button;
			};
			add_action( 'comment_form_submit_field', $func );
 
			// BACK-END
			$func=function($approved, $commentdata)
			{
				if ($this->helpers->referrer_is_external_domain())
				{
					$error=__("Uh-oh. Domains doesn't match.");
				}
				else if ( !$this->opts['enable_captcha_for_logged_in'] && is_user_logged_in() ){
					// nothing to do
				}
				else if ($this->opts['captcha_mode']=="simple")
				{
					if (!isset($_POST['rcf_antispam'])) 
						$error=__("Captcha not checked");
					else{
						if ( $_POST['rcf_antispam']!= get_the_date('d', $commentdata['comment_post_ID']) * pow($commentdata['comment_post_ID'], 2)*get_option('rcf_sample_random') )
							$error=__("Incorrect captcha. Go back and try again.");
					}
				}
				elseif ($this->opts['captcha_mode']=="recaptcha")
				{ 
					$response = $this->helpers->get_remote_data('https://www.google.com/recaptcha/api/siteverify?secret='.$this->opts['google_captcha_secret'].'&response='.$_POST['g-recaptcha-response']);
					$result = json_decode($response);
					if(!$result || !$result->success) $error = __('Robot verification failed, please try again.'); 
				}
				if (isset($error))
					return new \WP_Error( 'comment_recaptcha_failed',  " (". esc_html($error) .")", 200);
				return $approved;
			};
			add_filter('pre_comment_approved', $func, 50, 2);
		}
		// #####################################
		
	}
 
 
 
 
	// =================================== Options page ================================ //
	public function opts_page_output()
	{ 
		$this->settings_page_part("start", 'first'); 
		?>

		<style>  
		//.mainForm th{width:40%; min-width:200px; }
		</style>

		<?php if ($this->active_tab=="Options") 
		{ 
			//if form updated
			if( $this->checkSubmission() ) 
			{ 
				$this->opts['enable_email']			= !empty($_POST[ $this->plugin_slug ]['enable_email']); 
				$this->opts['enable_url']			= !empty($_POST[ $this->plugin_slug ]['enable_url']); 
				//
				$this->opts["custom_note"]			= str_replace(["\r\n","\n"], '<br/>', strip_tags(sanitize_text_field($_POST[ $this->plugin_slug ]["custom_note"])) ); 
				$this->opts["custom_note_css"]		= strip_tags(sanitize_text_field($_POST[ $this->plugin_slug ]["custom_note_css"]));  
				$this->opts["enable_comments_all"]	= strip_tags(sanitize_text_field($_POST[ $this->plugin_slug ]["enable_comments_all"]));  
				$this->opts['enable_attributes_in_comment']	= !empty($_POST[ $this->plugin_slug ]['enable_attributes_in_comment']); 
				$this->opts['enable_attributes_msg'] = strip_tags(sanitize_text_field($_POST[ $this->plugin_slug ]['enable_attributes_msg'])); 
				//
				$this->opts['captcha_mode'] = sanitize_key($_POST[ $this->plugin_slug ]['captcha_mode']); 
				$this->opts['enable_captcha_for_logged_in'] = !empty($_POST[ $this->plugin_slug ]['enable_captcha_for_logged_in']); 
				$this->opts['google_captcha_public'] = sanitize_text_field($_POST[ $this->plugin_slug ]['google_captcha_public']); 
				$this->opts['google_captcha_secret'] = sanitize_text_field($_POST[ $this->plugin_slug ]['google_captcha_secret']);

				$this->update_opts();
			}
			
			add_option('rcf_sample_random', rand(1,99999) );
			?>
			
			<?php _e("Disabling the fields will also make WP to ignore (but not delete) the existing comment data collected from those fields. For example, removing <code>URL</code> field also causes to hide-away the urls from the comments made in the past too. As long as this plugin is active, that behavior will be kept. Thus, in case of enabling that option back, WP will return back to it's default functionality. However, if you want to erase the existing data from database too, use <code>Delete existing data too</code> buttons" , 'disable-comment-fields'); ?>
			<form class="mainForm" method="post" action="">

			<table class="form-table">
			
				<tr class="def">
					<th scope="row">
						<?php _e("Option Name", 'disable-comment-fields'); ?>
					</th>
					<td>
						<?php _e("Option Description", 'disable-comment-fields'); ?>
					</td> 
					<td> 
						<?php _e("Option Value", 'disable-comment-fields'); ?>
					</td>
					<td>
						<?php _e("Clear data from Database", 'disable-comment-fields'); ?>
					</td>
				</tr>		

				
				<tr class="def">
					<th scope="row">
						<?php _e("Allow field: <code>Email</code>", 'disable-comment-fields'); ?>
					</th>
					<td> 
						<p class="description"><?php _e('Disabling this will override the <a href="'.admin_url("options-discussion.php#default_comment_status").'" target="_blank"><code>☐ Comment author must fill out name and email</code></a> setting.', "disable-comment-fields"); ?></p>
					</td> 
					<td> 
						<div class="">
							<input name="<?php echo $this->plugin_slug;?>[enable_email]" type="radio" value="0" <?php checked(!$this->opts['enable_email']); ?>><?php _e( 'No', 'disable-comment-fields' );?>
							<input name="<?php echo $this->plugin_slug;?>[enable_email]" type="radio" value="1" <?php checked($this->opts['enable_email']); ?>><?php _e( 'Yes', 'disable-comment-fields' );?>
						</div>
					</td>
					<td>
						<button onclick="" disabled="disabled"><?php _e("Delete existing data too", 'disable-comment-fields'); ?> (<?php _e("Coming soon", 'disable-comment-fields'); ?>)</button>
					</td>
				</tr>
				
				<tr class="def">
					<th scope="row">
						<?php _e("Allow field: <code>URL</code>", 'disable-comment-fields'); ?>
					</th>
					<td> 
						<p class="description"><i><?php printf(__('Note, the already registered users will still be able to make comments with URL in their name, because the <code>Website</code> field is used from their profiles. In order to disable that profile field, you will need to use %s plugin.'), '<a href="https://wordpress.org/plugins/remove-fields-from-profile-dashboard" target="_blank">Remove fields from Profile & Dashboard</a>');?></i></p>
					</td>
					<td>
						<fieldset>
							<div class="">
								<input name="<?php echo $this->plugin_slug;?>[enable_url]" type="radio" value="0" <?php checked(!$this->opts['enable_url']); ?>><?php _e( 'No', 'disable-comment-fields' );?>
								<input name="<?php echo $this->plugin_slug;?>[enable_url]" type="radio" value="1" <?php checked($this->opts['enable_url']); ?>><?php _e( 'Yes', 'disable-comment-fields' );?>
							</div>
						</fieldset> 
					</td>
					<td>
						<button onclick="" disabled="disabled"><?php _e("Delete existing data too", 'disable-comment-fields'); ?> (<?php _e("Coming soon", 'disable-comment-fields'); ?>)</button>
					</td>
				</tr>

				<tr class="def">
					<th scope="row">
						<?php _e("Enable HTML element attributes (including links) in comments", 'disable-comment-fields'); ?>
					</th>
					<td> 
						<p class="description"><i><?php _e('Disabling will help much. Firstly, this will disable ability to inlcude links in comments (so, it decreases spam-attraction), and in addition avoids the potential misuse/threat of comments functionality.') ;?></i></p>
					</td>
					<td>
						<fieldset>
							<div class="">
								<input name="<?php echo $this->plugin_slug;?>[enable_attributes_in_comment]" type="radio" value="0" <?php checked(!$this->opts['enable_attributes_in_comment']); ?>><?php _e( 'No', 'disable-comment-fields' );?>
								<input name="<?php echo $this->plugin_slug;?>[enable_attributes_in_comment]" type="radio" value="1" <?php checked($this->opts['enable_attributes_in_comment']); ?>><?php _e( 'Yes', 'disable-comment-fields' );?>
								<br/>
								<?php _e( 'Message to display when disabled:', 'disable-comment-fields' );?> : <input name="<?php echo $this->plugin_slug;?>[enable_attributes_msg]" type="text" value="<?php echo $this->opts['enable_attributes_msg']; ?>" class="large-text" />
								
							</div>
						</fieldset>
						
					</td>
					<td>
						<button onclick="" disabled="disabled"><?php _e("Delete from existing data too", 'disable-comment-fields'); ?> (<?php _e("Coming soon", 'disable-comment-fields'); ?>)</button>
					</td>
				</tr>
				
				
				<tr class="def">
					<th scope="row">
						<?php _e("Add custom message/note to comment form", 'disable-comment-fields'); ?>
					</th>
					<td> 
						<p class="description"></p>
					</td>
					<td>
						<fieldset>
							<div class="">
								<input name="<?php echo $this->plugin_slug;?>[custom_note]" type="text" class="large-text" value="<?php echo sanitize_text_field($this->opts['custom_note']); ?>"  />
								<br/>
								<input name="<?php echo $this->plugin_slug;?>[custom_note_css]" type="text" class="large-text" value="<?php echo sanitize_text_field($this->opts['custom_note_css']); ?>"  />
							</div>
						</fieldset>
					</td>
					<td>
						
					</td>
				</tr>
				
 
				<tr class="def">
					<th scope="row">
						<?php _e("Display comments on website:", 'disable-comment-fields'); ?>
					</th>
					<td> 
						<div>
							<?php _e("This option is just shorthand to disable comments easily with one click. However, the standard way in WordPress is:");?>  
							<ul>
								<li>- <?php _e('To disable comments globally for posts published from now, you\'d better go to <a href="'.admin_url("options-discussion.php").'" target="_blank">discussion</a> page and uncheck the checkbox ', 'disable-comment-fields'); ?> <code><?php _e('Allow people to submit comments on new posts');?></code>.</li>
								<li>- <?php _e("To disable comments to existing posts/pages, you'd better go to ALL POSTS/PAGES and BULK EDIT (or individual edit) to disable comments for them.", 'disable-comment-fields'); ?></li>
							</ul>
						</div>
					</td>
					<td> 
						<div class="">
							<input name="<?php echo $this->plugin_slug;?>[enable_comments_all]" type="radio" value="0" <?php checked( ! $this->opts['enable_comments_all']); ?>><?php _e( 'No', 'disable-comment-fields' );?>
							<input name="<?php echo $this->plugin_slug;?>[enable_comments_all]" type="radio" value="1" <?php checked(   $this->opts['enable_comments_all']); ?>><?php _e( 'Yes', 'disable-comment-fields' );?>
						</div> 
						
					</td>
					<td>
						<button onclick="" disabled="disabled"><?php _e("Delete existing data too", 'disable-comment-fields'); ?> (<?php _e("Coming soon", 'disable-comment-fields'); ?>)</button>
					</td>
				</tr>
				
 
				<tr class="def">
					<th scope="row">
						<?php _e("Use Captcha:", 'disable-comment-fields'); ?>
					</th>
					<td> 
						<?php _e('If this is your site, it\'s better to use Google ReCaptcha, because it is the best against spam ( <a href="https://www.google.com/recaptcha/admin" target="_blank">Generate api key</a>). However, you can use primitive,less sophisticated, simple captcha too, which is effective only against simple bots.');?>
					</td>
					<td> 
						<div class="">
							<input name="<?php echo $this->plugin_slug;?>[captcha_mode]" type="radio" value="none"      <?php checked($this->opts['captcha_mode']=="none"); ?>><?php _e( 'No', 'disable-comment-fields' );?>
							<br/><input name="<?php echo $this->plugin_slug;?>[captcha_mode]" type="radio" value="simple"    <?php checked($this->opts['captcha_mode']=="simple"); ?>><?php _e( 'Simple', 'disable-comment-fields' );?>
							<br/><input name="<?php echo $this->plugin_slug;?>[captcha_mode]" type="radio" value="recaptcha" <?php checked($this->opts['captcha_mode']=="recaptcha"); ?>><?php _e( 'Google_ReCaptcha', 'disable-comment-fields' );?>
						</div>
						<div id="google_captcha_mcf">
							<i><?php _e("Keys for Google ReCaptcha:", 'disable-comment-fields'); ?></i>
							<input name="<?php echo $this->plugin_slug;?>[google_captcha_public]" type="text" class="large-text" value="<?php echo sanitize_text_field($this->opts['google_captcha_public']); ?>" placeholder="PUBLIC_KEY" />
							<br/>
							<input name="<?php echo $this->plugin_slug;?>[google_captcha_secret]" type="text" class="large-text" value="<?php echo sanitize_text_field($this->opts['google_captcha_secret']); ?>" placeholder="SECRET_KEY" />
						</div>
					</td>
					<td>
						<input name="<?php echo $this->plugin_slug;?>[enable_captcha_for_logged_in]" type="checkbox" value="1" <?php checked( $this->opts['enable_captcha_for_logged_in']); ?>><?php _e( '(For logged-in users too)', 'disable-comment-fields' );?> 
						<i>(<?php _e("Note, if you have enabled captcha, and paralelly, you use Caching plugins,  the captcha might be still in html code, but won't be evaluated in backed for logged-in users", 'disable-comment-fields'); ?>)</i>
					</td>
				</tr>
				
			</table>
			
			<?php $this->nonceSubmit(); ?>

			</form>
		<?php 
		}
		

		$this->settings_page_part("end", '');
	} 



  } // End Of Class

  $GLOBALS[__NAMESPACE__] = new PluginClass();

} // End Of NameSpace

?>