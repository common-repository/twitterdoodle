<?php
/*
Plugin Name: Twitterdoodle
Plugin URI: http://www.lessnau.com/twitterdoodle/
Description:  Allows a user to create posts and categories based on Twitter keyword searches. Each post is a digest of twitter posts in that category. By <a href="http://www.lessnau.com/">The Lessnau Lounge</a> and <a href="http://scompt.com">Edward Dale</a>.
Version: 1.2
*/

require_once(ABSPATH.WPINC.'/class-snoopy.php');

define('TWITTERDOODLE_TWITTER_LANGUAGE', 'en');

class Twitterdoodle {
    var $json;
    var $snooper;
    var $keyword_table;
    var $tweet_table;

    /**
     * Sets up object-level variables and hooks into WordPress.
     */
    function Twitterdoodle() {
        global $wpdb;

        $this->keyword_table = $wpdb->prefix.'twitterdoodle_keywords';
        $this->tweet_table = $wpdb->prefix.'twitterdoodle_tweets';

        // Admin stuff
        $this->json = new Twitterdoodle_JSON();
        $this->snooper = new Twitterdoodle_Twitter($this->json);

        add_action('init', array(&$this, 'init'));
        add_action('activate_twitterdoodle/twitterdoodle.php', array(&$this, 'install'));
    	add_filter('cron_schedules', array(&$this, 'cron_schedules'));
    	add_action('twitterdoodle_ping', array(&$this, 'ping'));
    	add_action('twitterdoodle_post', array(&$this, 'post'));

        // Public stuff
    	add_action('pre_get_posts', array(&$this,'pre_get_posts'));
    	add_action('wp_head', array(&$this, 'styles'));
    }
    
    /**
     * Fills a tweet with lots of link-love.
     */
    function clickify($tweet, $keyword) {
        $tweet = make_clickable($tweet);
        $tweet = preg_replace('/^\@(\w*):? /', '<a rel="nofollow" href="http://twitter.com/$1">@$1</a> ', $tweet, 1);

        return $tweet;
    }
    
    /**
     * Adds styles to the theme.
     */
    function styles() {
        echo apply_filters('twitterdoodle_stylelink', '<link rel="stylesheet" href="'.trailingslashit(get_option('siteurl')).PLUGINDIR.'/twitterdoodle/twitterdoodle.css'.'" type="text/css" />');
    }
    
    /**
     * Determines which categories to include on the front page.
     */
    function pre_get_posts() {
        if( is_home() ) {
            add_filter('posts_where', array(&$this, 'no_fp_posts_where'));
            add_filter('posts_join', array(&$this, 'no_fp_posts_join'));
        } else if( is_feed() && $this->get_option('show_in_rss')=='N') {
            add_filter('posts_where', array(&$this, 'no_feeds_posts_where'));
            add_filter('posts_join', array(&$this, 'no_feeds_posts_join'));
        }
    }
    
    /**
     * Determines which categories to include on the front page.
     */
    function no_fp_posts_where($where) {
        global $wpdb;
        $where .= " AND $wpdb->postmeta.meta_key IS NULL OR $wpdb->postmeta.meta_key != '_twitterdoodle_fp_exclude'";
        return $where;
    }

    /**
     * Determines which categories to include on the front page.
     */
    function no_fp_posts_join($join) {
        global $wpdb;
        $join .= " LEFT JOIN $wpdb->postmeta";
		$join .= " ON $wpdb->posts.ID = $wpdb->postmeta.post_id";
		$join .= " AND $wpdb->postmeta.meta_key='_twitterdoodle_fp_exclude'";
		return $join;
    }
    
    /**
     * Determines which categories to include on the front page.
     */
    function no_feeds_posts_where($where) {
        global $wpdb;
        $where .= " AND (twitterdoodle_meta.meta_key IS NULL OR twitterdoodle_meta.meta_key != '_twitterdoodle_post')";
        return $where;
    }

    /**
     * Determines which categories to include on the front page.
     */
    function no_feeds_posts_join($join) {
        global $wpdb;
        $join .= " LEFT JOIN $wpdb->postmeta AS twitterdoodle_meta";
		$join .= " ON $wpdb->posts.ID = twitterdoodle_meta.post_id";
		$join .= " AND twitterdoodle_meta.meta_key='_twitterdoodle_post'";
		return $join;
    }

    /**
     * Posts the time-based tweets for a keyword.  Called from cron.
     */
    function post($keyword_id) {
        global $wpdb;

        kses_remove_filters(); // Lets us use div's and such.
        $this->update_tweets($keyword_id);
        $query = "SELECT * FROM {$this->keyword_table} WHERE id=$keyword_id";
        $kw = $wpdb->get_row($query);

        // TODO: delete the keyword cron event if it doesn't exist
        $this->post_keyword($keyword_id,$kw);
    }
    
    /**
     * Updates and posts the tweet-based keywords.  Called from cron.
     */
    function ping() {
        $this->update_tweets();
        $this->post_tweet_posts();
    }
    
    /**
     * Installs the tables/options necessary if they're not there.
     */
    function install() {
        global $wpdb;

        if( $wpdb->get_var( "SHOW TABLES LIKE '{$this->keyword_table}'" ) != $this->keyword_table ) {
            $sql1 = "CREATE TABLE `{$this->tweet_table}` (
              `id` bigint(20) NOT NULL auto_increment,
              `tweet` varchar(255) default NULL,
              `twitter_user_name` varchar(255) default NULL,
              `twitter_user_id` bigint(20) default NULL,
              `twitter_id` bigint(20) default NULL,
              `tweeted_date` datetime default '0000-00-00 00:00:00',
              `keyword_id` bigint(20) NOT NULL,
              PRIMARY KEY  (`id`),
              UNIQUE KEY `twitter_id` (`twitter_id`),
              KEY `twitter_user_id` (`twitter_user_id`),
              KEY `keyword_id` (`keyword_id`)
            )";
            $sql2 = "CREATE TABLE `{$this->keyword_table}` (
              `id` bigint(20) NOT NULL auto_increment,
              `keyword` varchar(255) NOT NULL default '',
              `search_string` varchar(255) NOT NULL default '',
              `last_searched` datetime default '0000-00-00 00:00:00',
              `last_tweet` bigint(20) default '0',
              `user_id` bigint(20) default '0',
              `update_interval` varchar(20) NOT NULL default '',
              `front_page` char(1) default 'Y',
              `category_id` bigint(20) default NULL,
              PRIMARY KEY  (`id`),
              UNIQUE KEY `keyword` (`keyword`,`user_id`)
            )";
            
    		require_once( ABSPATH . 'wp-admin/upgrade-functions.php' );
    		dbDelta( $sql1 );
    		dbDelta( $sql2 );
            
            $options = array('comment_order'       => 'DESC',
                             'links_nofollow'      => True,
                             'links_newwindow'     => True,
                             'max_tweets_per_post' => 50,
                             'my_username'         => '',
                             'my_password'         => '',
                             'ignores'             => array(),
                             'last_ignore'         => 0,
                             'last_error'          => '' );
            add_option('twitterdoodle', $options);
            
            wp_schedule_event(time(), 'halfhour', 'twitterdoodle_ping');

    	} else if( $wpdb->get_var( "SHOW COLUMNS FROM {$this->keyword_table} LIKE 'search_string'" ) != 'search_string' ) {
            $sql1 = "ALTER TABLE {$this->keyword_table} ADD COLUMN search_string VARCHAR(255) NOT NULL default ''";
            $sql2 = "UPDATE {$this->keyword_table} SET search_string=keyword";
            $wpdb->query($sql1);
            $wpdb->query($sql2);
        }
    }
    
    /**
     * Adds a couple extra schedules to the cron system.  'halfhour' is used for updating tweets.
     */
    function cron_schedules($scheds) {
        $extra_scheds = array('3days'=>array('interval'=>259200, 'display'=>__('Every 3 Days', 'twitterdoodle')),
                              'halfhour' => array('interval'=>1800, 'display'=>__('Half Hour', 'twitterdoodle')));
        return array_merge($extra_scheds, $scheds);
    }

    /**
     * Makes sure the plugin is installed.  Loads translations, sets up menu.  Handles POSTs.
     */
    function init() {
    	load_plugin_textdomain('twitterdoodle', PLUGINDIR.'/twitterdoodle');
    	
    	add_action('admin_menu', array(&$this, 'admin_menu'));
    	$this->handle_posts();
    }

    /**
     * Handles any POSTs made from the plugin.
     */
    function handle_posts() {
        if( isset($_POST['new_keyword'] ) ) {
            check_admin_referer("new-keyword");
            $keyword = stripslashes($_POST['keyword']);
            $search_string = stripslashes($_POST['search_string']);
            $category = $_POST['cat'];
            $interval = $_POST['update_interval'];
            $front_page = $_POST['front_page'];
            $this->add_keyword( $keyword, $search_string, $category, $interval, $front_page );
            wp_redirect("options-general.php?page=twitterdoodle_options&twitterdoodle_message=1&keyword_name=".urlencode(htmlentities($keyword, ENT_QUOTES)));

        } else if( isset($_POST['edit_keyword'] ) ) {
            $id = $_POST['id'];
            check_admin_referer("edit-keyword_$id");
            $keyword = stripslashes($_POST['keyword']);
            $search_string = stripslashes($_POST['search_string']);
            $interval = $_POST['update_interval'];
            $front_page = $_POST['front_page'];
            $category_id = $_POST['cat'];
            $this->edit_keyword( $id, $keyword, $search_string, $interval, $front_page, $category_id );
            wp_redirect("options-general.php?page=twitterdoodle_options&twitterdoodle_message=2&keyword_name=".urlencode(htmlentities($keyword, ENT_QUOTES)));

        } else if( isset($_GET['delete_keyword'])) {
            $id = $_GET['delete_keyword'];
            $name = stripslashes($_GET['keyword_name']);
            check_admin_referer("delete-keyword_$id");
            $this->delete_keyword($id);
            wp_redirect("options-general.php?page=twitterdoodle_options&twitterdoodle_message=3&keyword_name=".urlencode(htmlentities($name, ENT_QUOTES)));

        } else if( isset($_POST['update_options'] ) ) {
            check_admin_referer('update-options');
            
            $nofollow_links = $_POST['nofollow_links']=='nofollow';
            $newwindow_links = $_POST['newwindow_links']=='newwindow';
            $tweetorder = in_array($_POST['tweetorder'], array('ASC', 'DESC')) ? $_POST['tweetorder'] : 'ASC';
            $maxtweets = in_array($_POST['maxtweets'], array('0','10','20','50','100')) ? $_POST['maxtweets'] : '0';
            $ignores = explode(',',$_POST['ignores']);
            $username = $_POST['my_username'];
            $password = $_POST['my_password'];
            $show_in_rss = empty($_POST['show_in_rss'])?'N':'Y';

            $old_options = get_option('twitterdoodle');
            $new_options = array('comment_order'       => $tweetorder,
                                 'links_nofollow'      => $nofollow_links,
                                 'links_newwindow'     => $newwindow_links,
                                 'max_tweets_per_post' => $maxtweets,
                                 'ignores'             => $ignores,
                                 'show_in_rss'         => $show_in_rss,
                                 );

            if( empty($username) || empty($password)) {
                $new_options['my_username'] = '';
                $new_options['my_password'] = '';
            } else if( $password != '******' ) {
                $new_options['my_password'] = $password; // plaintext!
            }
            $new_options['my_username'] = $username;

            $options = array_merge($old_options, $new_options);
            update_option('twitterdoodle', $options);
            
            wp_redirect("options-general.php?page=twitterdoodle_options&twitterdoodle_message=4");
        }
    }
    
    /**
     * Updates a keyword with new settings.
     */
    function edit_keyword($id, $keyword, $search_string, $interval, $front_page, $category_id ) {
        global $wpdb;
        $id = (int)$id;
        $query = "UPDATE {$this->keyword_table} SET keyword=%s, search_string=%s, update_interval=%s, front_page=%s, category_id=%d WHERE id=%d";
        $query = $wpdb->prepare($query, $keyword, $search_string, $interval, empty($front_page) ? 'N':'Y', $category_id, $id);
        $wpdb->query($query);

        wp_clear_scheduled_hook('twitterdoodle_post', $id);
        if( strpos($interval, 'tweets') === False ) {
            // If it's a time-based keyword, then schedule the updates
            wp_schedule_event(time(), $interval, 'twitterdoodle_post', array($id));
        } else {
            // Otherwise, just do a single update
            wp_schedule_single_event(time(), 'twitterdoodle_ping');
        }
    }
    
    /**
     * Deletes an existing keyword.
     */
    function delete_keyword($id) {
        global $wpdb;
        
        $query = "DELETE FROM {$this->tweet_table} WHERE keyword_id=$id";
        $wpdb->query($query);
        
        $query = "DELETE FROM {$this->keyword_table} WHERE id=$id";
        $wpdb->query($query);
     
        wp_clear_scheduled_hook('twitterdoodle_post', (int)$id);   
    }
    
    /**
     * Adds a new keyword.
     */
    function add_keyword($keyword, $search_string, $category, $update_interval, $front_page) {
        global $wpdb, $user_ID;

        // Create a new category if needed
        if( $category == 'new' ) {
            $category = get_cat_ID("Twitter {$keyword}");
            if( $category == 0 ) {
                require_once(ABSPATH.'/wp-admin/includes/taxonomy.php');
                $category = wp_create_category("Twitter {$keyword}");   
            }
        }

        $query = "INSERT IGNORE INTO {$this->keyword_table} (keyword,search_string,update_interval,front_page,category_id,user_id) VALUES (%s,%s,%s,%s,%d,%d)";
        $query = $wpdb->prepare($query, $keyword, $search_string, $update_interval, empty($front_page) ? 'N':'Y', $category, $user_ID);
        $wpdb->query($query);

        if( strpos($update_interval, 'tweets') === False ) {
            // If it's a time-based keyword, then schedule the updates
            wp_clear_scheduled_hook('twitterdoodle_post', $wpdb->insert_id);
            wp_schedule_event(time(), $update_interval, 'twitterdoodle_post', array($wpdb->insert_id));
        } else {
            // Otherwise, just do a single update
            wp_schedule_single_event(time(), 'twitterdoodle_ping');
        }
    }
    
    /**
     * Add a link to the plugin to the menu.
     */
    function admin_menu() {
	    $page = add_options_page('Twitterdoodle', "Twitterdoodle", 'manage_options', 'twitterdoodle_options', array(&$this, 'options_page') );
	}
	
	/**
	 * Do the options page.
	 */
    function options_page() {
        global $wpdb;

        $options = get_option('twitterdoodle');
        
        // Show a message if needed
        if( isset($_GET['twitterdoodle_message']) ) {
            $messages = array( '1' => __("Successfully added the keyword search <b>%s</b>", 'twitterdoodle'),
                               '2' => __("Successfully edited the keyword search <b>%s</b>", 'twitterdoodle'),
                               '3' => __("Successfully deleted the keyword search <b>%s</b>", 'twitterdoodle'),
                               '4' => __("Successfully updated the options", 'twitterdoodle'));
            $keyword = stripslashes($_GET['keyword_name']);
            $msg = sprintf($messages[$_GET['twitterdoodle_message']], $keyword);

            echo "<div id=\"message\" class=\"updated fade\"><p>$msg</p></div>";
        }

        $keyword_query = "SELECT * FROM {$this->keyword_table}";
        $keywords = $wpdb->get_results($keyword_query);
        ?>
        <div class="wrap">
        <h2><?php _e("Keyword Searches", "twitterdoodle"); ?></h2>
        <p></p>
        <table class="widefat">
        <thead>
            <tr>
                <th><?php _e('Search String', 'twitterdoodle'); ?></th>
                <th><?php _e('Category', 'twitterdoodle'); ?></th>
                <th><?php _e('Interval', 'twitterdoodle'); ?></th>
                <th><?php _e('Front Page', 'twitterdoodle'); ?></th>
                <th colspan="2"><?php _e('Actions', 'twitterdoodle'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php
        if( empty($keywords) ) {
            ?>
            <tr colspan="5"><td><?php _e('You currently have no keyword searches.  Add one below!', 'twitterdoodle') ?></td></tr>
            <?php
        } else {
            $class = "";
            foreach( $keywords as $kw ) {
                echo "<tr id=\"search-{$kw->id}\" class=\"$class\">";
                echo "<td>". (empty($kw->search_string) ? __('<i>My tweets</i>', 'twitterdoodle') : $kw->search_string)."</td>\n";
                echo "<td>".get_cat_name($kw->category_id)."</td>\n";
                echo "<td>{$kw->update_interval}</td>\n";
                echo "<td>". ($kw->front_page=='Y' ? 'Yes' : 'No') ."</td>\n";
                echo "<td><a class='view' href='options-general.php?page=twitterdoodle_options&amp;twitterdoodle_edit={$kw->id}#twitterdoodle_edit'>Edit</a></td>\n";
                echo "<td><a class='delete' href='".wp_nonce_url("options-general.php?page=twitterdoodle_options&amp;delete_keyword={$kw->id}&amp;keyword_name={$kw->keyword}", 'delete-keyword_' . $kw->id)."' onclick=\"return confirm('" . js_escape(sprintf( __("You are about to delete the keyword '%s'.\n'OK' to delete, 'Cancel' to stop.", 'twitterdoodle'), $kw->keyword)) . "' );\">Delete</a></td>\n";
                echo "</tr>";
                $class = empty($class)?"alternate":"";
            }
        }        
        ?>
        </tbody>
        </table>
        </div>
        <div class="wrap narrow" id="twitterdoodle_edit">
        <?php
            if( isset($_GET['twitterdoodle_edit']) ) {
                $id = $_GET['twitterdoodle_edit'];

                $query = "SELECT * FROM {$this->keyword_table} WHERE id=$id";
                $keyword = $wpdb->get_row($query, ARRAY_A);

                $header = __(sprintf('Edit keyword search: %s', $keyword['keyword']), 'twitterdoodle');
                $header_text = __('Add the keywords or key word phrases you want your your twitter posts to contain.', 'twitterdoodle');
                $button_text = __('Edit Keyword Search &raquo;', 'twitterdoodle');
                $nonce_name = "edit-keyword_$id";
                $action_name = "edit_keyword";
                $after = "<input type=\"hidden\" name=\"id\" value=\"{$keyword['id']}\" />\n";
            } else {
                $header = __('Add new keyword search', 'twitterdoodle');
                $header_text = __('Add the keywords or key word phrases you want your your twitter posts to contain.', 'twitterdoodle');
                $button_text = __('Add Keyword Search &raquo;', 'twitterdoodle');
                $nonce_name = "new-keyword";
                $action_name = "new_keyword";
                $after = '';
                
                $keyword = array('keyword'=>'', 'category_id'=>get_option('default_category'), 'update_interval'=>'', 'front_page'=>'Y');
            }
            add_filter('wp_dropdown_cats', array(&$this, 'new_cat_option'));
        ?>
            <h2><?php echo $header ?></h2>
            <p><?php echo $header_text ?></p>
            <form method="post" action="options-general.php?page=twitterdoodle_options">
                <table width="100%" cellspacing="2" cellpadding="5" class="editform form-table"><tbody>
            		<tr>
            			<th width="33%" valign="top" scope="row"><label for="keyword"><?php _e('Post Keyword Name', 'twitterdoodle'); ?>:</label><br /><span style="font-size:xx-small">Post title will be "Posts about Keyword"</span></th>
            			<td width="67%"><input type="text" size="40" value="<?php echo htmlentities($keyword['keyword']) ?>" id="keyword" name="keyword"/></td>
            		</tr>
            		<tr>
            			<th width="33%" valign="top" scope="row"><label for="search_string"><?php _e('Keyword or Keyword String', 'twitterdoodle'); ?>:</label><br /><span style="font-size:xx-small">e.g. "General Election" or Obama Clinton.  Leave empty to create a post with your tweets (Username and password required).</span></th>
            			<td width="67%"><input type="text" size="40" value="<?php echo htmlentities($keyword['search_string']) ?>" id="search_string" name="search_string"/></td>
            		</tr>
            		<tr>
            			<th width="33%" valign="top" scope="row"><label for="category"><?php _e('Category', 'twitterdoodle'); ?>:</label></th>
            			<td width="67%"><?php wp_dropdown_categories("hide_empty=0&selected={$keyword['category_id']}") ?></td>
            		</tr>
            		<tr>
            			<th width="33%" valign="top" scope="row"><label for="update_interval"><?php _e('Interval', 'twitterdoodle'); ?>:</label></th>
            			<td width="67%"><?php $this->interval_dropdown($keyword['update_interval']) ?></td>
            		</tr>
            		<tr>
            			<th width="33%" valign="top" scope="row"><label for="front_page"><?php _e('Front Page', 'twitterdoodle'); ?>:</label></th>
            			<td width="67%"><input type="checkbox" <?php checked($keyword['front_page'], 'Y') ?> id="front_page" name="front_page"/></td>
            		</tr>
            	</tbody></table>
                <p class="submit"><input type="submit" value="<?php echo $button_text ?>" name="<?php echo $action_name ?>"/></p>
                <?php wp_nonce_field($nonce_name) ?>
                <?php echo $after ?>
            </form>
            
            <h2><?php _e('General Options', 'twitterdoodle') ?> <span style="font-size:small">(These options will be applied to all TwitterDoodle keyword posts)</span></h2>
            <form method="post" action="options-general.php?page=twitterdoodle_options">
                <table width="100%" cellspacing="2" cellpadding="5" class="editform form-table"><tbody>
            		<tr>
            			<th width="33%" valign="top" scope="row"><label for="nofollow_links"><?php _e('Nofollow links', 'twitterdoodle'); ?>:</label></th>
            			<td width="67%">
            			    <select id="nofollow_links" name="nofollow_links">
            			        <option value="follow" <?php selected($options['links_nofollow'], false) ?>>Follow</option>
            			        <option value="nofollow" <?php selected($options['links_nofollow'], true) ?>>No-follow</option>
            			    </select>
            			</td>
            		</tr>
            		<tr>
            			<th width="33%" valign="top" scope="row"><label for="newwindow_links"><?php _e('Links in new windows', 'twitterdoodle'); ?>:</label></th>
            			<td width="67%">
            			    <select id="newwindow_links" name="newwindow_links">
            			        <option value="newwindow" <?php selected($options['links_newwindow'], true) ?>>New Window</option>
            			        <option value="samewindow" <?php selected($options['links_newwindow'], false) ?>>Same Window</option>
            			    </select>
            			</td>
            		</tr>
            		<tr>
            			<th width="33%" valign="top" scope="row"><label for="tweetorder"><?php _e('Tweet order', 'twitterdoodle'); ?>:</label></th>
            			<td width="67%">
            			    <select id="tweetorder" name="tweetorder">
            			        <option value="ASC" <?php selected($options['comment_order'], 'ASC') ?>>Ascending</option>
            			        <option value="DESC" <?php selected($options['comment_order'], 'DESC') ?>>Descending</option>
            			    </select>
            			</td>
            		</tr>
            		<tr>
            			<th width="33%" valign="top" scope="row"><label for="maxtweets"><?php _e('Max tweets per post', 'twitterdoodle'); ?>:</label></th>
            			<td width="67%">
            			    <select id="maxtweets" name="maxtweets">
            			        <option value="10" <?php selected($options['max_tweets_per_post'], 10) ?>>10</option>
            			        <option value="20" <?php selected($options['max_tweets_per_post'], 20) ?>>20</option>
            			        <option value="50" <?php selected($options['max_tweets_per_post'], 50) ?>>50</option>
            			        <option value="100" <?php selected($options['max_tweets_per_post'], 100) ?>>100</option>
            			        <option value="0" <?php selected($options['max_tweets_per_post'], 0) ?>>All Available</option>
            			    </select>
            			</td>
            		</tr>
            		<tr>
            			<th width="33%" valign="top" scope="row"><label for="ignores"><?php _e('Ignored Users', 'twitterdoodle'); ?>:</label><br /><span style="font-size:xx-small">Users who tweet 'twitterdoodle ignore' will be automatically listed here.  You can also manually add usernames separated by commas.</span></th>
            			<td width="67%"><input type="text" size="40" value="<?php echo implode(',',$options['ignores']); ?>" id="ignores" name="ignores"/></td>
            		</tr>
            		<tr>
            			<th width="33%" valign="top" scope="row"><label for="my_username"><?php _e('Twitter Account', 'twitterdoodle'); ?>:</label><br /><span style="font-size:xx-small">Only needed if you're posting your tweets also.</span></th>
            			<td width="67%"><input type="text" size="40" value="<?php echo $options['my_username'] ?>" id="my_username" name="my_username"/><br /><input type="password" size="40" value="<?php if (!empty($options['my_password'])) echo '******' ?>" id="my_password" name="my_password"/></td>
            		</tr>
            		<tr>
            			<th width="33%" valign="top" scope="row"><label for="show_in_rss"><?php _e('Show in RSS Feed', 'twitterdoodle'); ?>:</label><br /><span style="font-size:xx-small"></span></th>
            			<td width="67%"><input type="checkbox" <?php checked($options['show_in_rss'], 'Y') ?> id="show_in_rss" name="show_in_rss"/></td>
            		</tr>
            	</tbody></table>
                <p class="submit"><input type="submit" value="<?php _e('Update Options', 'twitterdoodle') ?>" name="update_options"/></p>
                <?php wp_nonce_field('update-options') ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * Filter for the wp_dropdown_cats function to add a 'new category' option.
     */
    function new_cat_option($cat_dropdown) {
        $replacement = '<option value="new">'.__('New Category', 'twitterdoodle').'</option></select>';
        return str_replace('</select>', $replacement, $cat_dropdown);
    }
    
    /**
     * Displays a dropdown with the possible intervals.
     */
    function interval_dropdown($current=False) {
        $options = array("Time-based" => array('daily'=>'Every day', '3days'=>'Every 3 days', 'weekly'=>'Weekly'),
                         'Tweet-based' => array('5tweets'=>'5 Tweets', '10tweets'=>'10 Tweets', '50tweets'=>'50 Tweets'));

        echo "<select id=\"update_interval\" name=\"update_interval\">";
        foreach( $options as $name=>$intervals ) {
            echo "<optgroup label=\"$name\">\n";
            foreach( $intervals as $key=>$display ) {
                echo '<option ';
                selected($current,$key);
                echo " value=\"$key\">$display</option>\n";
            }
            echo "</optgroup>\n";
        }
        echo "</select>\n";
    }
    
    /**
     * Posts all of the tweet-based keywords.
     */
    function post_tweet_posts() {
        global $wpdb;
        
        // Clever query that returns all tweet-based keywords with the minimum number of tweets satisfied
        $keywords_query  = "SELECT {$this->keyword_table}.*, COUNT({$this->keyword_table}.id) AS tweet_count ";
        $keywords_query .= "FROM {$this->keyword_table} ";
        $keywords_query .= "JOIN {$this->tweet_table} ON {$this->keyword_table}.id = {$this->tweet_table}.keyword_id ";
        $keywords_query .= "WHERE update_interval LIKE '%tweets' ";
        $keywords_query .= "GROUP BY {$this->keyword_table}.id ";
        $keywords_query .= "HAVING tweet_count >= CONVERT(TRIM(TRAILING 'tweets' FROM update_interval), SIGNED)";        

        $keywords = $wpdb->get_results($keywords_query);

        kses_remove_filters(); // Allows us to post div's
        foreach( $keywords as $kw ) {
            $this->post_keyword($kw->id, $kw);
        }
    }

    /**
     * Posts a keyword.
     *
     * $kw object Keyword from database, if it's already been retrieved.
     */
    function post_keyword($id, $kw=NULL) {
        global $wpdb;

        $tweets_query = "SELECT * FROM {$this->tweet_table} WHERE keyword_id={$id} ORDER BY twitter_id ".$this->get_option('comment_order');
        if( $this->get_option('max_tweets_per_post') != 0 ) {
            $tweets_query .= " LIMIT ".$this->get_option('max_tweets_per_post');
        }

        $tweets = $wpdb->get_results($tweets_query);
        if( !empty( $tweets ) ) {
            if( $kw == NULL )
                $kw = $wpdb->get_row("SELECT * FROM {$this->keyword_table} WHERE id={$id}");

            $post_array = $this->build_post_array($tweets,$kw);
            if( !empty($post_array)) {
                $result = wp_insert_post($post_array);
                if( $result != 0 ) {
                    if( $kw->front_page=='N') {
                        $data = array( 'post_id' => $result, 'meta_key' => '_twitterdoodle_fp_exclude', 'meta_value' => '1' );
                		$wpdb->insert( $wpdb->postmeta, $data );
            		}
            		$data = array( 'post_id' => $result, 'meta_key' => '_twitterdoodle_post', 'meta_value' => '1' );
            		$wpdb->insert( $wpdb->postmeta, $data );
                }
            }

            // Delete the tweets that were posted.
            $update_query = "DELETE FROM {$this->tweet_table} WHERE keyword_id={$id}";
            $wpdb->query($update_query);
        } else {
            $this->update_option('last_error', "No tweets for $id");
        }
    }
    
    /**
     * Utility function to get a twitterdoodle option.
     */
    function get_option($name) {
        $options = get_option('twitterdoodle');
        return $options[$name];
    }
    
    /**
     * Utility function to set a twitterdoodle options.
     */
    function update_option($name, $value) {
        $options = get_option('twitterdoodle');
        $options[$name] = $value;
        update_option('twitterdoodle',$options);
    }
    
    /**
     * Creates the posts for a given keyword and set of tweets.
     */
    function build_post_array($tweets,$keyword) {
        global $wpdb;
        
        $post = array('post_status' => 'publish', 'post_type' => 'post',
    		'ping_status' => get_option('default_ping_status'), 'post_parent' => 0,
    		'menu_order' => 0);

        $date = mysql2date(get_option('date_format'), current_time('mysql'));

    	$post['post_category'] = array($keyword->category_id);
        $post['post_author'] = $keyword->user_id;
        $username = $this->get_option('my_username');
        
        // If keyword is empty, it's 'our' tweets
        if( !empty($keyword->keyword) ) {
            $post['post_title'] = "Twitter Tweets about {$keyword->keyword} as of $date";
            $post['post_title'] = str_replace('"', '', $post['post_title']);
        } else if( !empty($username)) {
            $username = $this->get_option('my_username');
            $post['post_title'] = "Twitter Tweets from {$username} as of $date";
        } else {
            return array();
        }
    	
    	$content = '<div class="twitterdoodle">';
    	foreach( $tweets as $tweet ) {
    	    $content .= "<div class=\"tweet\"><div class=\"tweet_text\">\n";
    	    $content .= "<a rel='nofollow' href=\"http://twitter.com/{$tweet->twitter_user_name}\">{$tweet->twitter_user_name}</a>";
            $content .= ": ".$this->clickify($tweet->tweet, $keyword)."</div>\n<div class=\"tweet_meta\">";
            $content .= mysql2date('Y-m-d H:i:s', $tweet->tweeted_date)." &middot; <a rel='nofollow' href=\"http://twitter.com/home?status=@{$tweet->twitter_user_name}\">Reply</a> ";
            $content .= "&middot; <a rel='nofollow' href=\"http://twitter.com/{$tweet->twitter_user_name}/statuses/{$tweet->twitter_id}\">View</a>";
            $content .= "</div></div>\n";
    	}
        if( $this->get_option('links_nofollow') === False) {
            $content = str_replace(array(' rel="nofollow"', " rel='nofollow'"), '', $content);
        }
        if( $this->get_option('links_newwindow') == True) {
            $content = str_replace('<a ', '<a target="_blank" ', $content);
        }

    	$footer = apply_filters('twitterdoodle_footer', array('TwitterDoodle by <a href="http://www.lessnau.com/twitterdoodle/">The Lessnau Lounge</a>') );
        $content .= '<div class="twitterdodle_footer">'.implode(' ', $footer).'</div>';
    	$content .= '</div>';

    	$post['post_content'] = $wpdb->escape($content);
    	
    	$post = apply_filters('twitterdoodle_post_array', $post);
    	
    	return $post;
    }

    /**
     * Retrieves the tweets for the username stored in the database
     */
    function get_my_tweets($last_tweet) {
        $results = array();
        
        $password = $this->get_option('my_password');
        $username = $this->get_option('my_username');

        if( !empty($username)) {
            $twitter_snooper = new Twitterdoodle_Twitter($this->json);
            $results = $twitter_snooper->get_user($username, $password, $last_tweet);
        }
    
        return $results;
    }

    /**
     * Checks to see if anyone wants to be ignored.
     */
    function check_ignores() {
        $results = $this->snooper->get_keyword('"twitterdoodle ignore"', $this->get_option('last_ignore'));

        foreach( $results['results'] as $tweet ) {
            if( preg_match('/^twitterdoodle ignore$/i', $tweet['tweet']) ) {
                $ignores = $this->get_option('ignores');
                $ignores []= $tweet['username'];
                $ignores = array_unique($ignores);
                $this->update_option('ignores', $ignores);
            }
        }
        $this->update_option('last_ignore', $results['max']);
    }

    /**
     * Updates everything that needs to be updated:
     * * my tweets
     * * tweet-based keywords
     * * time-based keywords
     * * ignores
     */
    function update_tweets($keyword_id=NULL) {
        global $wpdb;
        set_time_limit(0);

        $this->check_ignores();
        
        $query = "SELECT id, keyword, search_string, last_tweet FROM {$this->keyword_table}";

        if( !is_null($keyword_id) ) {
            $query .= " WHERE id=$keyword_id";
        }
        $keywords = $wpdb->get_results($query);
        if( empty($keywords) ) {
            $this->update_option('last_error', "No keywords needing updating");
            return;
        }

        $ignores = $this->get_option('ignores');
        foreach( $keywords as $kw ) {
            // Get the tweets for the keyword (or my tweets)
            if( empty($kw->keyword) ) {
                $results = $this->get_my_tweets($kw->last_tweet);
            } else {
                $results = $this->snooper->get_keyword($kw->search_string, $kw->last_tweet);
            }

            // If there were new tweets, insert them into the database in one go
            if( !empty($results ) ) {
                $insert  = "INSERT IGNORE INTO {$this->tweet_table} (tweet,";
                $insert .= "twitter_user_name,twitter_user_id,twitter_id";
                $insert .= ",tweeted_date,keyword_id) VALUES ";

                $values = array();
                foreach( $results['results'] as $tweet ) {
                    if( !in_array($tweet['username'], $ignores) ) {
                        $aValue  = "('". $wpdb->escape($tweet['tweet']);
                        $aValue .= "','". $wpdb->escape($tweet['username']);
                        $aValue .= "','". $tweet['user_id'];
                        $aValue .= "','". $tweet['tweet_id'];
                        $aValue .= "','". $tweet['date'];
                        $aValue .= "','". $kw->id ."')";

                        $values []= $aValue;
                    }
                }
                $insert .= implode(',', $values);
                $success = $wpdb->query($insert);
                if( $success === false ) {
                    $this->update_option('last_error', "Could not update tweet table");
                }

                // Update the keyword with the most recent search
                $update_keyword  = "UPDATE {$this->keyword_table} SET ";
                $update_keyword .= "last_tweet='". $results['max'] ."',";
                $update_keyword .= "last_searched='". current_time('mysql') ."' ";
                $update_keyword .= "WHERE id={$kw->id}";

                $success = $wpdb->query($update_keyword);
                if( $success === false ) {
                    $this->update_option('last_error', "Could not update keyword table");
                }
                
            } else {
                $this->update_option('last_error', "Got empty results back");
            }
        }
    }    
}

/**
 * A class to get tweets from Twitter.
 */
class Twitterdoodle_Twitter {
    var $snoop;
    var $json;
    
    /**
     * Setup object-level variables.
     */
    function Twitterdoodle_Twitter($json) {
        $this->json = $json;
        $this->snoop = new Snoopy;
        $this->snoop->agent = 'Twitterdoodle';
        $this->snoop->rawheaders = array('X-Twitter-Client' => 'Twitterdoodle');
    }

    /**
     * Searching on Twitter isn't supported yet.
     */
    function get_keyword($keyword, $last) {
        $params = array('q'    => $keyword,
                        'rpp'  => 100, 
                        'lang' => TWITTERDOODLE_TWITTER_LANGUAGE);
        if( $last>0 ) $params['since_id'] = $last;

        $this->snoop->submit('http://search.twitter.com/search.json', $params);
        
        if( $this->snoop->status == '200' ) {
            $snoop_results = $this->json->decode($this->snoop->results);
            $results = array();
            
            foreach( $snoop_results->results as $tweet ) {
                $results []= array('tweet' => $tweet->text,
                                   'username' => $tweet->from_user,
                                   'user_id' => $tweet->from_user_id,
                                   'tweet_id' => $tweet->id,
                                   'date' => $this->format_date($tweet->created_at));
            }
            return array('results'=>$results, 'max'=>$snoop_results->max_id); 
        } else {
            return array();
        }
    }

    /**
     * Gets the posts for a user with login credentials.
     */
    function get_user($username, $password, $last) {
        $this->snoop->user = $username;
        $this->snoop->pass = $password;
        
        $params = array('id' => $username, 'since_id'=>$last);
    
        $this->snoop->submit('http://twitter.com/statuses/user_timeline.json', $params);
        if( $this->snoop->status == '200' ) {
            $results = array();
            $tweets = $this->json->decode($this->snoop->results);
            foreach( $tweets as $tweet ) {
                $results []= array(   'tweet' => $tweet->text,
                                   'username' => $tweet->user->name,
                                    'user_id' => $tweet->user->id,
                                   'tweet_id' => $tweet->id,
                                       'date' => $this->format_date($tweet->created_at));
            }
            return array('results'=>$results, 'max'=>$tweets[0]->id);
        } else {
            return array();
        }
    }
    
    /**
     * Reformats the dates provided by Twitter to something a bit more logical.
     */
    function format_date($date) {
        $parts = explode(' ', $date);
        $date = strftime("%Y-%m-%d %H:%M:%S", strtotime($parts[2].' '.$parts[1].', '.$parts[3].' '.$parts[4]));
        return $date;
    }
}

// A PHP4- and PHP5-safe way to do JSON decoding.
if( !function_exists('json_encode' ) ) {
    if( !class_exists('Services_JSON') ) 
        require_once('JSON.php');
        
    class Twitterdoodle_JSON {
        var $json;
        function Twitterdoodle_JSON() {
            $this->json = new Services_JSON();
        }
        function encode($in) {
            return $this->json->encode($in);
        }
        function decode($in) {
            return $this->json->decode($in);
        }
    }
} else {
    class Twitterdoodle_JSON {
        function encode($in) {
            return json_encode($in);
        }
        function decode($in) {
            return json_decode($in);
        }
    }
}

// Gets the show on the road.
new Twitterdoodle();
?>