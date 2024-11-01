<?php
/*
Plugin Name: Tweet Tweet
Plugin URI: http://ocaoimh.ie/tweet-tweet/
Description: Archive your Twitter conversations
Version: 0.5.7
Author: Donncha O Caoimh
Author URI: http://ocaoimh.ie/
*/
/*  Copyright Donncha O Caoimh (http://ocaoimh.ie/)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License version 2 as 
    published by the Free Software Foundation; 

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

$wpdb->twitterarchives = $wpdb->prefix . 'twitter_archives';
$wpdb->twitterprofiles = $wpdb->prefix . 'twitter_profiles';

function tweet_tweet_init() {
	$plugins = glob( dirname( __FILE__ ) . '/plugins/*.php' );
	if( is_array( $plugins ) ) {
		foreach ( $plugins as $plugin ) {
			if( is_file( $plugin ) )
				require_once( $plugin );
			}
	}
}
add_action( 'init', 'tweet_tweet_init' );

function tweet_tweet_update_db() {
	global $wpdb;
	$version = get_option( 'tweet_tweet_db_version', 1 );
	if ( $version == 1 ) {
		$wpdb->query( 'ALTER TABLE `' . $wpdb->twitterarchives . '` ADD  `in_reply_to_id` BIGINT( 20 ) NOT NULL' );
		$wpdb->query( 'ALTER TABLE `' . $wpdb->twitterarchives . '` ADD  `user_id` BIGINT( 20 ) NOT NULL' );
		$wpdb->query( 'ALTER TABLE `' . $wpdb->twitterarchives . '` ADD INDEX (  `in_reply_to_id` )' );
		$wpdb->query( 'ALTER TABLE `' . $wpdb->twitterarchives . '` ADD INDEX (  `user_id` )' );
		$wpdb->query( 'CREATE TABLE IF NOT EXISTS `' . $wpdb->twitterprofiles . '` (
		  		`id` bigint(20) NOT NULL,
		    		`screen_name` varchar(255) NOT NULL,
		      		`protected` smallint(1) NOT NULL,
		        	`meta` text NOT NULL,
			  	PRIMARY KEY (`id`),
			    	KEY `screen_name` (`screen_name`)
			    	);' );
		update_option( 'tweet_tweet_db_version', 2 );
	}
}

function tweet_tweet_install() {
	global $wpdb;

	$wpdb->twitterarchives = $wpdb->prefix . 'twitter_archives';
	$wpdb->twitterprofiles = $wpdb->prefix . 'twitter_profiles';
	if($wpdb->get_var("SHOW TABLES LIKE '$wpdb->twitterarchives'") == $wpdb->twitterarchives)
		return true;

	$sql = "CREATE TABLE {$wpdb->twitterarchives} (
			`tid` bigint(20) NOT NULL,
			`user_id` bigint(20) NOT NULL,
			`username` varchar(100) NOT NULL,
			`description` varchar(180) NOT NULL,
			`in_reply_to_id` bigint(20) NOT NULL,
			`pubdate` varchar(60) NOT NULL,
			KEY `username` (`username`),
			KEY `tid` (`tid`),
			KEY `in_reply_to_id` (`in_reply_to_id`)
			)";
	$wpdb->query( 'CREATE TABLE IF NOT EXISTS `' . $wpdb->twitterprofiles . '` (
		  	`id` bigint(20) NOT NULL,
		    	`screen_name` varchar(255) NOT NULL,
		      	`protected` smallint(1) NOT NULL,
		        `meta` text NOT NULL,
			PRIMARY KEY (`id`),
			KEY `screen_name` (`screen_name`)
			);' );
	require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
	dbDelta($sql);
}
register_activation_hook(__FILE__,'tweet_tweet_install');

function tweet_tweet_add_pages() {
	add_options_page('Twitter', 'Twitter', 'manage_options', 'twitter-conf', 'tweet_tweet_options');
}
add_action('admin_menu', 'tweet_tweet_add_pages');

function tweet_tweet_options() {
	global $wpdb, $tweet_tweet_oauth;
	tweet_tweet_update_db();
	$message = '';
	$tweet_tweet_oauth = get_option( 'tweet_tweet_oauth', array() );
	if ( isset( $_POST['action'] ) && $_POST['action'] == 'options' ) {
		if ( function_exists('current_user_can') && !current_user_can('manage_options') )
			die(__('Cheatin&#8217; uh?'));

		check_admin_referer( 'tweet_tweet' );
		$interval = (int)$_POST[ 'interval' ] >= 90 ? (int)$_POST[ 'interval' ] : 90;
		
		update_option( 'tweet_tweet', apply_filters( 'tweet_tweet_options', array( 'interval' => $interval, 'review' => (int)$_POST[ 'review' ], 'limit' => (int)$_POST[ 'limit' ], 'maxtweets' => (int)$_POST[ 'maxtweets' ] ) ) );
		archive_tweets();
	} elseif( isset( $_POST[ 'action' ] ) && $_POST[ 'action' ] == 'tweet_tweet_oauth_test' ) {
		if (!wp_verify_nonce($_POST['_wpnonce'], 'tweet_tweet_oauth_test')) {
			wp_die('Oops, please try again.');
		}
		$auth_test = false;
		if ( !empty($_POST['tweet_tweet_app_consumer_key'])
			&& !empty($_POST['tweet_tweet_app_consumer_secret'])
			&& !empty($_POST['tweet_tweet_oauth_token'])
			&& !empty($_POST['tweet_tweet_oauth_token_secret'])
		) {
			foreach( array( 'app_consumer_secret', 'oauth_token', 'oauth_token_secret', 'app_consumer_key' ) as $key ) {
				$tweet_tweet_oauth[ $key ] = $_POST[ 'tweet_tweet_' . $key ];
			}
			$message = 'failedoauth';
			if ($connection = tweet_tweet_oauth_connection( $tweet_tweet_oauth )) {
				$data = $connection->get('account/verify_credentials');
				if ($connection->http_code == '200') {
					update_option( 'tweet_tweet_oauth', $tweet_tweet_oauth );
					$data = json_decode($data);
					update_option('tweet_tweet_twitter_username', stripslashes($data->screen_name));
					$oauth_hash = tweet_tweet_oauth_credentials_to_hash();
					update_option('tweet_tweet_oauth_hash', $oauth_hash);
					$message = 'connectedoauth';
				}
			}
		}
	} elseif( isset( $_POST[ 'action' ] ) && $_POST[ 'action' ] == 'tweet_tweet_twitter_disconnect' ) {
		if (!wp_verify_nonce($_POST['_wpnonce'], 'aktt_twitter_disconnect')) {
			wp_die('Oops, please try again.');
		}
				
		update_option('tweet_tweet_oauth', '' );
		$message = 'twitterdisconnected';
	}
	if ( !empty($_POST ) ) { 
		switch( $message ) {
			case "failedoauth":
			?> <div id="message" class="updated fade"><p><strong><?php _e('Failed to connect to Twitter. Please check your oAuth credentials below.', 'tweet-tweet') ?></strong></p></div> <?php 
			break;
			case "connectedoauth":
			?> <div id="message" class="updated fade"><p><strong><?php _e('Connected to Twitter successfully.', 'tweet-tweet') ?></strong></p></div> <?php 
			break;
			case "twitterdisconnected":
			?> <div id="message" class="updated fade"><p><strong><?php _e('Disconnected from Twitter successfully.', 'tweet-tweet') ?></strong></p></div> <?php 
			break;
			default:
			?> <div id="message" class="updated fade"><p><strong><?php _e('Options saved.', 'tweet-tweet' ) ?></strong></p></div> <?php 
			break;
		}
	} 
	$options = get_option( 'tweet_tweet' );
	$username = '';
	$password = '';
	$interval = 180;
	$review = 1;
	$limit = 10;
	$maxtweets = 0;
	if( is_array( $options ) )
		extract( $options );
	$stats = get_option( 'tweet_tweet_count' ); 
	if ( isset( $stats ) && (int)$stats[ 'max' ][ 'friends' ] != 0 && (int)$stats[ 'max' ][ 'friends' ] < 100 ) {
		echo '<div id="message" class="updated fade"><p>' . __( 'You should probably increase the <em>Update Interval</em> on this admin page as Twitter will deliver up to 200 tweets at a time and you&#8217;re nowhere near that. API requests from this plugin count towards the Twitter limit and may disrupt other clients you use. Please ignore if you have increased the interval.', 'tweet-tweet' ) . '<p></div>';
	}
	?>
	<div class="wrap">
	<h2><?php _e('Twitter Archiver'); ?></h2>
	<div class="narrow">
	<?php
	if ( is_array( $stats ) ) {
		echo "
			<div style='float: right'>
			<h3>" . __( 'Stats', 'tweet-tweet' ) . "</h3>
			<table><tr><th>Tweets</th><th>Last</th><th>Max</th></tr>
			<tr><td>" . __( 'Home', 'tweet-tweet' ) . "</td><td>" . (int)$stats[ 'friends' ] . "</td><td>" . (int)$stats[ 'max' ][ 'friends' ] . "</td></tr>
			<tr><td>" . __( 'Mentions', 'tweet-tweet' ) . "</td><td>" . (int)$stats[ 'mentions' ] . "</td><td>" . (int)$stats[ 'max' ][ 'mentions' ] . "</td></tr>
			<tr><td>" . __( 'Direct', 'tweet-tweet' ) . "</td><td>" . (int)$stats[ 'direct' ] . "</td><td>" . (int)$stats[ 'max' ][ 'direct' ] . "</td></tr>
			<tr><td>" . __( 'Direct', 'tweet-tweet' ) . "</td><td>" . (int)$stats[ 'direct-sent' ] . "</td><td>" . (int)$stats[ 'max' ][ 'direct-sent' ] . "</td></tr>
			</table></div>";
	}
	$tweets_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->twitterarchives}" );
	if ( !tweet_tweet_oauth_test() ) {
		print('	
			<h3>'.__('Connect to Twitter','tweet-tweet').'</h3>
			<p style="width: 700px;">'.__('In order to get started, we need to follow some steps to get this site registered with Twitter. This process is awkward and more complicated than it should be. We hope to have a better solution for this in a future release, but for now this system is what Twitter supports.', 'tweet-tweet').'</p> 
			<form id="ak_twittertools" name="ak_twittertools" action="'.admin_url('options-general.php?page=twitter-conf').'" method="post">
				<fieldset class="options">
					<h4>' . sprintf( __( '1. Register this site as an application on <a href="%s" title="Twitter App Registration" target="_blank">Twitter&#8217;s app registration page</a>', 'tweet-tweet' ), 'http://dev.twitter.com/apps/new' ) . '</h4>
					<div id="aktt_sub_instructions">
						<ul>
						<li>'.__('If you&#8217;re not logged in, you can use your Twitter username and password' , 'tweet-tweet').'</li>
						<li>'.__('Your Application&#8217;s Name will be what shows up after "via" in your twitter stream' , 'tweet-tweet').'</li>
						<li>'.__('Application Type should be set on ' , 'tweet-tweet').'<strong>'.__('Browser' , 'tweet-tweet').'</strong></li>
						<li>'.sprintf( __( 'The Callback URL should be: <strong>%s</strong>' , 'tweet-tweet'), get_bloginfo( 'url' ) ) . '</li>
						<li>'.__('Default Access type should be set to ' , 'tweet-tweet').'<strong>'.__('Read-only' , 'tweet-tweet').'</strong></li>
						</ul>
					<p>'.__('Once you have registered your site as an application, you will be provided with a consumer key and a comsumer secret.' , 'tweet-tweet').'</p>
					</div>
					<h4>'.__('2. Copy and paste your consumer key and consumer secret into the fields below' , 'tweet-tweet').'</h4>
				
					<div class="option">
						<label for="aktt_app_consumer_key">'.__('Twitter Consumer Key', 'tweet-tweet').'</label>
						<input type="text" size="25" name="tweet_tweet_app_consumer_key" id="tweet_tweet_app_consumer_key" value="'.esc_attr($tweet_tweet_oauth[ 'app_consumer_key' ]).'" autocomplete="off">
					</div>
					<div class="option">
						<label for="tweet_tweet_app_consumer_secret">'.__('Twitter Consumer Secret', 'tweet-tweet').'</label>
						<input type="text" size="25" name="tweet_tweet_app_consumer_secret" id="tweet_tweet_app_consumer_secret" value="'.esc_attr($tweet_tweet_oauth[ 'app_consumer_secret' ]).'" autocomplete="off">
					</div>
					<h4>3. Copy and paste your Access Token and Access Token Secret into the fields below</h4>
					<p>On the right hand side of your application page, click on &#8217;My Access Token&#8217;.</p>
					<div class="option">
						<label for="tweet_tweet_oauth_token">'.__('Access Token', 'tweet-tweet').'</label>
						<input type="text" size="25" name="tweet_tweet_oauth_token" id="tweet_tweet_oauth_token" value="'.esc_attr($tweet_tweet_oauth[ 'oauth_token' ]).'" autocomplete="off">
					</div>
					<div class="option">
						<label for="tweet_tweet_oauth_token_secret">'.__('Access Token Secret', 'tweet-tweet').'</label>
						<input type="text" size="25" name="tweet_tweet_oauth_token_secret" id="tweet_tweet_oauth_token_secret" value="'.esc_attr($tweet_tweet_oauth[ 'oauth_token_secret' ] ).'" autocomplete="off">
					</div>
				</fieldset>
				<p class="submit">
					<input type="submit" name="submit" class="button-primary" value="'.__('Connect to Twitter', 'tweet-tweet').'" />
				</p>
				<input type="hidden" name="action" value="tweet_tweet_oauth_test" class="hidden" style="display: none;" />
				'.wp_nonce_field('tweet_tweet_oauth_test', '_wpnonce', true, false).wp_referer_field(false).'
			</form>
				
				');
	} else {
		print('	
			<form id="ak_twittertools_disconnect" name="ak_twittertools_disconnect" action="'.admin_url('options-general.php').'" method="post">
				<h3>' . __( "Logged in to Twitter", 'tweet-tweet' ) . '</h3>
				<table>
					<tr><th align="left">'.__('Twitter Username: ', 'tweet-tweet').'</th><td>'.get_option( 'tweet_tweet_twitter_username' ).'</td></tr>
					<tr><th align="left">'.__('Consumer Key: ', 'tweet-tweet').'</th><td>'.$tweet_tweet_oauth[ 'app_consumer_key' ].'</td></tr>
					<tr><th align="left">'.__('Consumer Secret: ', 'tweet-tweet').'</th><td>'.$tweet_tweet_oauth[ 'app_consumer_secret' ].'</td></tr>
					<tr><th align="left">'.__('Access Token: ', 'tweet-tweet').'</th><td>'.$tweet_tweet_oauth[ 'oauth_token' ].'</td></tr>
					<tr><th align="left">'.__('Access Token Secret: ', 'tweet-tweet').'</th><td>'.$tweet_tweet_oauth[ 'oauth_token_secret' ].'</td></tr>
				</table>
				<p class="submit">
				<input type="submit" name="submit" class="button-primary" value="'.__('Disconnect Your WordPress and Twitter Account', 'tweet-tweet').'" />
				</p>
				<input type="hidden" name="ak_action" value="tweet_tweet_twitter_disconnect" class="hidden" style="display: none;" />
				'.wp_nonce_field('tweet_tweet_twitter_disconnect', '_wpnonce', true, false).wp_referer_field(false).' 
			</form>' );
		if ( $connection = tweet_tweet_oauth_connection() ) {
			if ( isset( $_GET[ 'action' ] ) ) {
				$c = isset( $_GET[ 'c' ] ) ? (int)$_GET[ 'c' ] : 0;
				if ( isset( $_GET[ 'screen_name' ] ) && $_GET[ 'screen_name' ] == '' )
					wp_die( __( "Warning! Screen name must be filled in!", 'tweet-tweet' ) );
				$screen_name = isset( $_GET[ 'screen_name' ] ) ? $_GET[ 'screen_name' ] : '';
				$type = '';
				switch( $_GET[ 'action' ] ) {
					case "refill":
						$tweets = $connection->get( 'http://api.twitter.com/1/statuses/user_timeline.json?count=200&page=' . $c );
						$type = 'friends';
						$max = 16;
					break;
					case "refilluser":
						$tweets = $connection->get( 'http://api.twitter.com/1/statuses/user_timeline.json?count=200&screen_name=' . $screen_name . '&page=' . $c );
						$type = 'friends';
						$max = 16;
					break;
					case "refillhome":
						$tweets = $connection->get( 'http://api.twitter.com/1/statuses/home_timeline.json?count=200&page=' . $c );
						$type = 'friends';
						$max = 4;
					break;
				}
				if ( strpos( $tweets, 'Please wait a moment and try again. For more information, check out <a href="http://status.twitter.com">Twitter Status &raquo;</a></p>' ) ) {
					$url = str_replace( 'amp;', '', wp_nonce_url( admin_url( "options-general.php?page=twitter-conf&c={$c}&action={$_GET[ 'action' ]}" ), 'tweet_tweet_refill' ) );
					if ( $screen_name != '' )
						$url .= '&screen_name=' . urlencode( $screen_name );
					tweet_tweet_reload( $url );
					wp_die( "Twitter is over capacity. This page will reload in 90 seconds. Failure to wait may result in a ban." );
				}
				$tweets = json_decode( preg_replace('/"id":(\d+)/', '"id":"$1"', $tweets ) );
				if ( $type != '' && $tweets ) {
					$results = tweet_tweet_record_tweets( $tweets, $type, true );
					echo "<strong>Tweets received:</strong> {$results[ 'count' ]}<br />";
					echo "<strong>Tweets recorded:</strong> {$results[ 'insert' ]}<br /><br />";
					if ( ($c + 1) <= $max ) {
						$url = str_replace( 'amp;', '', wp_nonce_url( admin_url( "options-general.php?page=twitter-conf&c=" . ($c + 1) . "&action={$_GET[ 'action' ]}" ), 'tweet_tweet_refill' ) );
						if ( $screen_name != '' )
							$url .= '&screen_name=' . urlencode( $screen_name );
						tweet_tweet_reload( $url );
					}
				} else {
					echo "<strong>No more tweets!</strong>";
				}
			}
		}
	}

	?>
	<h3><?php _e( 'Refill My Tweets', 'tweet-tweet' ); ?></h3>
	<p><?php _e( 'Download up to 3200 of my own tweets.', 'tweet-tweet' ); ?></p>
	<form action="<?php echo admin_url( 'options-general.php' ) ?>" method="GET" id="twitter-conf">
	<input type='hidden' name='page' value='twitter-conf' />
	<input type='hidden' name='action' value='refill' />
	<input type='hidden' name='c' value='1' />
	<input type='submit' class="button-primary" value='Refill my Tweets' />
	<?php wp_nonce_field('tweet_tweet_refill'); ?>
	</form>
	<h3><?php _e( 'Refill User Tweets', 'tweet-tweet' ); ?></h3>
	<p><?php _e( 'Download up to 3200 of another user&#8217;s tweets. If they are private and you&#8217;re not following them this won&#8217;t work.', 'tweet-tweet' ); ?></p>
	<form action="<?php echo admin_url( 'options-general.php' ) ?>" method="GET" id="twitter-conf">
	<input type='hidden' name='page' value='twitter-conf' />
	<input type='hidden' name='action' value='refilluser' />
	<input type='hidden' name='c' value='1' />
	Screen Username: <input type='text' name='screen_name' value='' /><br />
	<input type='submit' class="button-primary" value='Refill User' />
	<?php wp_nonce_field('tweet_tweet_refill'); ?>
	</form>
	<h3><?php _e( 'Refill Home Tweets', 'tweet-tweet' ); ?></h3>
	<p><?php _e( 'Download up to 800 tweets from your friends.', 'tweet-tweet' ); ?></p>
	<form action="<?php echo admin_url( 'options-general.php' ) ?>" method="GET" id="twitter-conf">
	<input type='hidden' name='page' value='twitter-conf' />
	<input type='hidden' name='action' value='refillhome' />
	<input type='hidden' name='c' value='1' />
	<input type='submit' class="button-primary" value='Refill Home' />
	<?php wp_nonce_field('tweet_tweet_refill'); ?>
	</form>
	<p><?php _e( '* Tweets are downloaded in bundles of 200 at a time.', 'tweet-tweet' ); ?></p>
	<h2><?php _e( 'Settings', 'tweet-tweet' ); ?></h2>
	<form action="<?php echo admin_url( 'options-general.php?page=twitter-conf' ) ?>" method="post" id="twitter-conf">
	<p><label>Update Interval: <input id="interval" name="interval" type="text" size="4" maxlength="4" value="<?php echo $interval ?>" /> seconds. (minimum 90 sec)</label></p>
	<h3>Twitter Review Pane</h3>
	<p><label>Enabled: <input id="review" name="review" type="checkbox" value="1"<?php if( $review ) echo " checked"; ?> /></label></p>
	<p><label>Tweets to show: <input id="limit" name="limit" type="text" size="3" value="<?php echo $limit; ?>" /></label></p>
	<p>"I'm Here" and "I'm Away" is your Away From Keyboard or AFK status. If you're <em>here</em> you probably don't want text notifications so that's disabled.</p>
	<h3>Archiving</h3>
	<p>The database table holding your Tweets will get quite large over time. If you follow a large number of people you could have thousands of tweets saved after only a week or two. This can cause problems for some servers.</p>
	<p>You can set the maximum number of Tweets to record in the main table before archiving in a timestamped archive table. Leave blank to disable. Min 1000. I use 100000 on my own server.</p>
	<p><label>Max Tweets <input id='maxtweets' name='maxtweets' type='text' size='10' maxlength='10' value='<?php echo $maxtweets; ?>' /></label> (<?php echo $tweets_count; ?> tweets currently saved)</p>
	<p>Archived tweets are not yet searchable through the Preview pane.</p>
	<?php do_action( 'tweet_tweet_config_page' ); ?>
	<?php wp_nonce_field('tweet_tweet'); ?>
	<input type='hidden' name='action' value='options' />
	<p><input type='submit' name='submit' value='Save Options' /></p>
	</form>
	</div>
	</div>
	<?php
}

function tweet_tweet_reload( $url ) {
	?><p><?php _e( 'If your browser doesn&#8217;t start loading the next page automatically in 90 seconds, click this link:', 'tweet-tweet' ); ?> <a class="button" href="<?php echo $url; ?>"><?php _e( "Next Tweets", 'tweet-tweet' ); ?></a></p>
	<script type='text/javascript'>
	<!--
	function nextpage() {
		location.href = "<?php echo $url; ?>";
	}
	setTimeout( "nextpage()", 90000 );
	//-->
	</script><?php
}

function archive_tweets() {
	global $wpdb;
	tweet_tweet_update_db();
	$options = get_option( 'tweet_tweet' );
	if( !is_array( $options ) )
		return;
	$interval = $options[ 'interval' ] >= 90 ? $options[ 'interval' ] : 90;
	wp_clear_scheduled_hook( 'tweet_tweet' );
	if( !wp_next_scheduled( 'tweet_tweet' ) )
		wp_schedule_single_event(time()+$interval, 'tweet_tweet');
	$last_tweet = get_option( 'last_tweet' );
	if( !$last_tweet ) {
		$last_tweet = time() - $interval;
		add_option( 'last_tweet', $last_tweet );
	}
	
	// need to bypass any caching for mutex flag
	$mutex = mt_rand();
	if( !$wpdb->query( "UPDATE {$wpdb->options} SET option_value='{$mutex}' WHERE option_name='twitter_mutex'" ) )
		$wpdb->query( "INSERT INTO {$wpdb->options} ( `option_name`, `option_value`, `autoload` ) VALUES ( 'twitter_mutex', '{$mutex}', 'no' )" );
	sleep(2*mt_rand( 1, 2 ) );
	$m = $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name='twitter_mutex'" );
	if( $mutex != $m )
		return;
	if( $last_tweet > ( time() - $interval + 3 ) )
		return;
	update_option( 'last_tweet', time() );

	if ( false == tweet_tweet_oauth_test() || false == ($connection = tweet_tweet_oauth_connection()) )
		return false; // oAuth failed

	timer_start();
	$c = 1;
	$finished = false;
	$tweet_count = get_option( 'tweet_tweet_count', array() );
	$max = 0;
	do {
		sleep( 10 ); // don't overload the Twitter API
		$tweets = json_decode( preg_replace('/"id":(\d+)/', '"id":"$1"', $connection->get( 'http://api.twitter.com/1/statuses/home_timeline.json?count=200&page=' . $c ) ) );
		$results = tweet_tweet_record_tweets( $tweets, 'friends' );
		if ( $max < $results[ 'insert' ] && (int)$tweet_count[ 'friends' ] < $results[ 'insert' ] )
			$max = $results[ 'insert' ];
		// grab more tweets if we recorded over 150 of the current lot.
		if ( $results[ 'insert' ] > 150 ) {
			$c++;
		} else {
			$finished = true;
		}
		if ( $c > 4 )
			$finished = true;
	} while ( $finished == false );
	if ( $max > 0 ) {
		$tweet_count[ 'friends' ] = $max;
		if ( (int)$tweet_count[ 'max' ][ 'friends' ] < $max )
			$tweet_count[ 'max' ][ 'friends' ] = $max;
		update_option( 'tweet_tweet_count', $tweet_count );
	}
	unset( $tweets );

	update_option( 'twitter_feed_count', (int)get_option( 'twitter_feed_count' ) + 1 );
	if( get_option( 'twitter_feed_count' ) > 5 ) {
		$tweets[ 'mentions' ] = json_decode( preg_replace('/"id":(\d+)/', '"id":"$1"', $connection->get( 'http://api.twitter.com/1/statuses/mentions.json' ) ) );

		if( get_option( 'twitter_sent_feed_count' ) >= 3 ) {
			update_option( 'twitter_sent_feed_count', 0 );
			$rows = $wpdb->get_results( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'rss\_%\_ts' AND option_value < unix_timestamp( date_sub( NOW(), interval 7200 second ) ) LIMIT 0, 500" );
			if( is_array( $rows ) ) {
				foreach( $rows as $row ) {
					$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", $row->option_name ) );
					$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", str_replace( '_ts', '', $row->option_name ) ) );
				}
			}
			$tweets[ 'direct' ] = json_decode( preg_replace('/"id":(\d+)/', '"id":"$1"', $connection->get( 'http://api.twitter.com/1/direct_messages.json' ) ) );
			$tweets[ 'direct-sent' ] = json_decode( preg_replace('/"id":(\d+)/', '"id":"$1"', $connection->get( 'http://api.twitter.com/1/direct_messages/sent.json' ) ) );
		} else {
			update_option( 'twitter_sent_feed_count', (int)get_option( 'twitter_sent_feed_count' ) + 1 );
		}
		update_option( 'twitter_feed_count', 0 );
	}
	$tweet_count = get_option( 'tweet_tweet_count', array() );
	foreach ( (array)$tweets as $title => $tweet_messages ) {
		tweet_tweet_record_tweets( $tweet_messages, $title );
		$count = count( $tweet_messages );
		if ( (int)$tweet_count[ 'max' ][ $title ] < $count )
			$tweet_count[ 'max' ][ $title ] = $count;
		$tweet_count[ $title ] = $count;
		if ( $tweet_count[ $title ] > 150 ) {
			wp_mail( get_option( 'admin_email' ), sprintf( __( 'Warning! %1$s %2$s tweets downloaded!', 'tweet-tweet' ), $tweet_count[ $title ], $title ), __( "Twitter puts a max limit of 200 tweets per request on their API. Please consider decreasing the Update Interval on the Tweet Tweet admin page." ) );
		}
	}
	update_option( 'tweet_tweet_count', $tweet_count );
	$r = timer_stop();
	//error_log( "fetching $count new tweets took $r seconds" );
}
add_action( 'tweet_tweet', 'archive_tweets' );

function tweet_tweet_record_tweets( $tweets, $type, $display = false ) {
	global $wpdb;
	$count = 0;
	$insert = 0;
	$first = true;
	foreach ( (array)$tweets as $tweet ) {
		if ( is_object( $tweet ) == false )
			continue;
		$tid = $tweet->id_str;
		$description = $tweet->text;
		$pubdate = substr( $tweet->created_at, 0, 3 ) . ", " . substr( $tweet->created_at, 8, 2 ) . " " . substr( $tweet->created_at, 4, 3 ) . " " . substr( $tweet->created_at, -4 ) . " " . substr( $tweet->created_at, 12, 14 );
		switch ( $type ) {
			case "friends":
				case "mentions":
				$username = $tweet->user->screen_name;
			break;
			case "direct-sent":
				$username = "(d) " . $tweet->recipient_screen_name;
			break;
			case "direct":
				$username = $tweet->sender_screen_name . " (d)";
			break;
		}

		if ( $display && $first ) {
			echo "FIRST: $tid $username $pubdate $description<br />";
			$first = false;
		}
		if( null == $wpdb->get_row( "SELECT tid from {$wpdb->twitterarchives} WHERE tid = '{$tid}'" ) ) {
			if ( $display )
				echo "RECORDED: $pubdate $description<br />";
			$wpdb->query( "INSERT INTO {$wpdb->twitterarchives} ( `tid`, `user_id`, `username`, `description`, `in_reply_to_id`, `pubdate` ) VALUES ( '" . $wpdb->escape( $tid ) . "', '" . (int)$tweet->user->id_str . "', '" . addslashes( $username ) . "', '" . $wpdb->escape( $description ) . "', '" . (int)$tweet->in_reply_to_status_id_str . "', '{$pubdate}' )" );
			$wpdb->query( "INSERT INTO {$wpdb->twitterprofiles} ( `id`, `screen_name`, `protected`, `meta` ) VALUES ( '" . (int)$tweet->user->id_str . "', '" . $wpdb->escape( $username ) . "', '" . (int)$tweet->user->protected . "', '" . $wpdb->escape( serialize( $tweet->user ) ) . "' ) ON DUPLICATE KEY UPDATE screen_name = '" . $wpdb->escape( $username ) . "', protected = '" . (int)$tweet->user->protected . "', meta = '" . $wpdb->escape( serialize( $tweet->user ) ) . "'" );
			$insert ++;
		}

		if( $type == 'direct' ) {
			$username = substr( $username, 0, -3 );
			do_action( 'tweet_tweet_direct', array( 'tid' => $wpdb->escape( $tid ), 'username' => $username, 'description' => $description, 'pubdate' => $pubdate ) );
		} elseif ( $type == 'direct-sent' ) {
			$username = substr( $username, 3 );
			do_action( 'tweet_tweet_direct_sent', array( 'tid' => $wpdb->escape( $tid ), 'username' => $username, 'description' => $description, 'pubdate' => $pubdate ) );
		} else {
			if( strpos( strtolower( $description ) , '@' . strtolower( $options[ 'username' ] ) ) !== false )
				do_action( 'tweet_tweet_reply', array( 'tid' => $wpdb->escape( $tid ), 'username' => $username, 'description' => $description, 'pubdate' => $pubdate ) );
		}
		do_action( 'tweet_tweet_tweet', array( 'tid' => $wpdb->escape( $tid ), 'username' => $username, 'description' => $description, 'pubdate' => $pubdate ) );
		$count ++;
	}
	return array( "count" => $count, "insert" => $insert );
}

function tweet_tweet_search_tweets( $sql, $start, $twitter_limit ) {
	global $wpdb;
	$options = get_option( 'tweet_tweet' );
	if ( false == is_array( $options[ 'tables' ] ) ) {
		$options[ 'tables' ] = $wpdb->twitterarchives;
	}
	$list = array();
	foreach ( $options[ 'tables' ] as $table ) {
		if ( count( $list ) > 10000 )
			continue;
		$tweets = $wpdb->get_results( sprintf( $sql, $wpdb->escape( $table ) ) );
		if ( is_array( $tweets ) ) {
			foreach( $tweets as $tweet )
				$list[ $tweet->tid ] = $tweet;
		}
	}
	arsort( $list );
	return $list;
}
function tweet_tweet_maybe_rename_db() {
	global $wpdb;

	$maxtweets = 0;
	$options = get_option( 'tweet_tweet' );
	if( !is_array( $options ) )
		return false;
	extract( $options );

	if ( (int)$maxtweets < 1000 ) {
		return false;
	}

	if ( (int)$maxtweets < $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->twitterarchives}" ) ) {
		$wpdb->query( "RENAME TABLE {$wpdb->twitterarchives} TO {$wpdb->twitterarchives}_" . time() );
		$tables = $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->twitterarchives}%'" );
		if ( false == empty( $tables ) ) {
			$options = get_option( 'tweet_tweet' );
			$options[ 'tables' ] = $tables;
			update_option( 'tweet_tweet', $options );
		}
		tweet_tweet_install();
	}
}
add_action( 'tweet_tweet_db_check', 'tweet_tweet_maybe_rename_db' );

function twitter_review_pane() {
	global $wpdb;
	$options = get_option( 'tweet_tweet' );
	if( !is_array( $options ) )
		return;

	if( !wp_next_scheduled( 'tweet_tweet' ) ) {
		wp_clear_scheduled_hook( 'tweet_tweet' );
		wp_schedule_single_event(time()+$options[ 'interval' ], 'tweet_tweet');
	}

	if( !wp_next_scheduled( 'tweet_tweet_db_check' ) ) {
		wp_clear_scheduled_hook( 'tweet_tweet_db_check' );
		wp_schedule_event( time()+$options[ 'interval' ], 'daily', 'tweet_tweet_db_check' );
	}

	if( $options[ 'review' ] == 0 )
		return;
	if( !isset( $options[ 'here' ] ) ) {
		$options[ 'here' ] = 1;
		update_option( 'tweet_tweet', $options );

	}

	$nonce = wp_create_nonce( 'twitterupdate' );
	$twitter_limit = $options[ 'limit' ] >= 10 ? $options[ 'limit' ] : 10;
?>
<script  type='text/javascript'>
var twitterajaxenabled = 1;
var twittertweets = 'friends';
var twitterpage = 1;
var searchtwitterusername = '';
var searchtwitterdescription = '';
function toggle_twitter_here( update ) {
	jQuery.ajax({
		type: "post",url: "admin-ajax.php",data: { action: 'twitteraway', away: update, _ajax_nonce: '<?php echo $nonce; ?>' },
	}); //close jQuery.ajax(
}

function toggle_twitter_updates( update ) {
	jQuery.ajax({
		type: "post",url: "admin-ajax.php",data: { action: 'twitter'+update, _ajax_nonce: '<?php echo $nonce; ?>' },
		success: function(html){ //so, if data is retrieved, store it in html
			jQuery(".twittercontent").fadeIn("fast"); //animation
		}
	}); //close jQuery.ajax(
}
function get_twitter_updates() { //start function when any link is clicked
	jQuery(".twittercontent").slideUp("fast");
	jQuery.ajax({
		type: "post",
		url: "admin-ajax.php",
		data: { action: 'twitterupdate', twittertweets: twittertweets, _ajax_nonce: '<?php echo $nonce; ?>', twitterpage: twitterpage, twitterusername: searchtwitterusername, twitterdescription: searchtwitterdescription },
		success: function(html){ //so, if data is retrieved, store it in html
			if( twittertweets != 'search' ) {
				jQuery('#hidesearchtwitter').attr( 'id', 'searchtwitter' );
				jQuery("#searchtwitterform").fadeOut("fast");
			}
			jQuery(".twittercontent").slideDown("slow"); //animation
			jQuery(".twittercontent").html(html); //show the html inside .twittercontent div
		}
	}); //close jQuery.ajax(
}
 
function update_twitter_review() {
	get_twitter_updates();
	jQuery('#twitterpagetitle').html( ucfirst( twittertweets ) + ', page ' + twitterpage );
}

function ucfirst( str ) {
	var f = str.charAt(0).toUpperCase();
	return f + str.substr(1, str.length-1);
}
function show_twitterreviewpane() {
	jQuery('#twitterreview').removeClass('twitterreviewpane');
	jQuery('#twitterreview').addClass('twitterreviewpanehover');
}
function hide_twitterreviewpane() {
	jQuery('#twitterreview').removeClass('twitterreviewpanehover');
	jQuery('#twitterreview').addClass('twitterreviewpane');
}

// When the document loads do everything inside here ...
jQuery(document).ready(function(){
		<?php if( $options[ 'update' ] == 1 ) { echo "		setTimeout( update_twitter_review, 92000 );\n"; } ?>
		jQuery('#twittermenu a').click(function() { //start function when any link is clicked
			if( jQuery(this).attr("id") == 'stopajax' ) {
				twitterajaxenabled = 0;
				jQuery(this).html( '[U]' );
				jQuery(this).attr( 'id', 'startajax' );
				toggle_twitter_updates( 'stop' );
			} else if( jQuery(this).attr("id") == 'startajax' ) {
				twitterajaxenabled = 1;
				jQuery(this).html( '[X]' );
				jQuery(this).attr( 'id', 'stopajax' );
				update_twitter_review();
				toggle_twitter_updates( 'start' );
				setTimeout( update_twitter_review, 92000 );
			} else if( jQuery(this).attr("id") == 'afkajax' ) {
				jQuery(this).html( '<strong>I\'m Here</strong>' );
				jQuery(this).attr( 'id', 'hereajax' );
				toggle_twitter_here( 'here' );
			} else if( jQuery(this).attr("id") == 'hereajax' ) {
				jQuery(this).html( '<strong>I\'m Away</strong>' );
				jQuery(this).attr( 'id', 'afkajax' );
				toggle_twitter_here( 'away' );
			} else if( jQuery(this).attr("id") == 'oldertwitter' ) {
				twitterpage = twitterpage + 1;
				update_twitter_review();
			} else if( jQuery(this).attr("id") == 'newertwitter' ) {
				if( twitterpage > 1 ) {
					twitterpage = twitterpage - 1;
					update_twitter_review();
				}
			} else if( jQuery(this).attr("id") == 'directtwitter' ) {
				twitterpage = 1;
				twittertweets = 'direct';
				update_twitter_review();
			} else if( jQuery(this).attr("id") == 'friendstwitter' ) {
				twitterpage = 1;
				twittertweets = 'friends';
				update_twitter_review();
			} else if( jQuery(this).attr("id") == 'repliestwitter' ) {
				twitterpage = 1;
				twittertweets = 'replies';
				update_twitter_review();
			} else if( jQuery(this).attr("id") == 'searchtwitter' ) {
				jQuery(this).attr( 'id', 'hidesearchtwitter' );
				jQuery("#searchtwitterform").fadeIn("slow");
			} else if( jQuery(this).attr("id") == 'hidesearchtwitter' ) {
				jQuery(this).attr( 'id', 'searchtwitter' );
				jQuery("#searchtwitterform").fadeOut("slow");
			}
		})
		// http://remysharp.com/2007/03/05/jquery-ajaxed-forms/
		// http://nettuts.com/javascript-ajax/submit-a-form-without-page-refresh-using-jquery/
		jQuery( function() {
			jQuery( '#twittersearchform' ).submit( function() {
				twitterpage = 1;
				twittertweets = 'search';
				searchtwitterdescription = escape( jQuery( '#searchtwitterdescription' ).val() );
				searchtwitterusername = escape( jQuery( '#searchtwitterusername' ).val() );
				update_twitter_review();
				return false;
			})
		})
		// http://www.learningjquery.com/2007/02/quick-tip-set-hover-class-for-anything
		jQuery('#twittermenudiv').hover( show_twitterreviewpane, hide_twitterreviewpane );
		jQuery('#twitterreview').hover( show_twitterreviewpane, hide_twitterreviewpane );
})//close jQuery(
</script>
	<style type="text/css">
	.twitterreviewpanehover {
		position:absolute; left: auto; right: 0px; z-index: 1; top: 45px; width: 50%; <?php if( $twitter_limit <= 15 ) { echo "height: auto; "; } else { echo "overflow-y: scroll; height: 50%; "; } ?> background: #fff; border: 1px solid #333; display: block; padding: 2px;  padding-top: 5px;
	}
	.twitterreviewpane {
		position:absolute; left: auto; right: 0px; z-index: 1; top: 45px; width: 50%; height: 45px; background: #fff; border: 1px solid #333; <?php if( $twitter_limit > 15 ) { echo "overflow-y: scroll;"; } else { echo "overflow: hidden;"; } ?> padding: 2px; padding-top: 5px;
	}
	#loading { clear:both; background:url(images/loading.gif) center top no-repeat; text-align:center;padding:33px 0px 0px 0px; font-size:12px;display:none; font-family:Verdana, Arial, Helvetica, sans-serif; }
	#searchtwitterform { clear:both; text-align:center;padding:5px 0px 5px 0px; font-size:12px;display:none; font-family:Verdana, Arial, Helvetica, sans-serif; }
	#twittermenudiv {position:absolute; left: auto; right: 0px; z-index: 2; top: 30px; width: 50%; height: 10px; background: #fff; border: 1px solid #333; padding: 2px; padding-top: 5px;}
	#twittermenu  {list-style:none; margin:0px; padding:0px; margin-top: -5px; padding-bottom: 1px; height: 11px; text-align: right;}
	#twittermenu li {list-style:none; display:inline; font-size: 11px; margin-left: 10px; }
	#twittermenu a {text-decoration: none;}
	</style>
	<div id='twittermenudiv'><ul id='twittermenu'><li id='twitterpagetitle'>Friends, page 1</li><li><a href="#" id='friendstwitter'>Friends</a><li><a href="#" id='repliestwitter'>Replies</a><li><a href="#" id='directtwitter'>Direct</a></li><li><a href="#" id='searchtwitter'>Search</a></li><li><a href="#" style='margin-left: 20px' id='newertwitter'>&lt;&lt; Newer</a></li><li><a href="#" id='oldertwitter'>Older >></a></li><li style='margin-left: 10px;'><a href="options-general.php?page=twitter-conf">[s]</a></li><li style='margin-left: 10px;'><a href='#' <?php if( $options[ 'update' ] == 1 ) { echo "id='stopajax'>[X]"; } else { echo "id='startajax'>[U]"; } ?></a></li><li><a href='#' <?php if( $options[ 'here' ] == 1 ) { echo "id='hereajax'><strong>I'm Here</strong>"; } else { echo "id='afkajax'><strong>I'm Away</strong>"; } ?></a></li></li></ul></div>
	<div id='twitterreview' class='twitterreviewpane'>
	<div id="searchtwitterform"><form action="" method="POST" id='twittersearchform'>Username: <input type="text" id="searchtwitterusername" name="searchtwitterusername" value="" /> Tweet: <input type="text" id="searchtwitterdescription" name="searchtwitterdescription" value="" /> <input type="submit" name="dosearchtwitter" value="Search Twitter" /></form></div>
	<div id="loading">LOADING</div>
	<div class="twittercontent"><?php twitter_update_ajax() ?></div>
	</div><?php
}
add_action( 'admin_footer', 'twitter_review_pane' );

function toggle_twitter_updates() {
	check_ajax_referer( "twitterupdate" );
	$options = get_option( 'tweet_tweet' );
	$options[ 'update' ] = 1;
	if( $_POST[ 'action' ] == 'twitterstop' )
		$options[ 'update' ] = 0;
	update_option( 'tweet_tweet', $options );
	die();
}
add_action( 'wp_ajax_twitterstop', 'toggle_twitter_updates' );
add_action( 'wp_ajax_twitterstart', 'toggle_twitter_updates' );

function toggle_twitter_away() {
	check_ajax_referer( "twitterupdate" );
	$options = get_option( 'tweet_tweet' );
	$options[ 'here' ] = 1;
	if( $_POST[ 'away' ] == 'away' )
		$options[ 'here' ] = 0;
	update_option( 'tweet_tweet', $options );
	die();
}
add_action( 'wp_ajax_twitteraway', 'toggle_twitter_away' );

function twitter_update_ajax() {
	global $wpdb;
	if( $_POST[ 'action' ] == 'twitterupdate' || $_POST[ 'twittertweets' ] )
		check_ajax_referer( "twitterupdate" );
	$options = get_option( 'tweet_tweet' );
	if( !is_array( $options ) ) {
		?>Please enter your Twitter username and password in the <a href="options-general.php?page=twitter-conf">options page</a><?php
		die();
	}
	$twitter_limit = $options[ 'limit' ] >= 10 ? $options[ 'limit' ] : 10;
	if( $_POST[ 'twitterpage' ] ) {
		$start = $twitter_limit * ( (int) $_POST[ 'twitterpage' ] - 1 );
	} else {
		$start = 0;
	}
	if( $_POST[ 'twittertweets' ] == 'direct' ) {
		$tweets = $wpdb->get_results( "SELECT * FROM {$wpdb->twitterarchives} WHERE username LIKE '%(d)%' ORDER by tid DESC LIMIT $start,$twitter_limit" );
	} elseif( $_POST[ 'twittertweets' ] == 'replies' ) {
		$tweets = $wpdb->get_results( "SELECT * FROM {$wpdb->twitterarchives} WHERE description LIKE '%@{$options[ 'username' ]}%' ORDER by tid DESC LIMIT $start,$twitter_limit" );
	} elseif( $_POST[ 'twittertweets' ] == 'search' ) {
		$where = '';
		if( $_POST[ 'twitterusername' ] )
			$where .= " username LIKE '%" . $wpdb->escape( rawurldecode( $_POST[ 'twitterusername' ] ) ) . "%' AND ";
		if( $_POST[ 'twitterdescription' ] )
			$where .= " description LIKE '%" . $wpdb->escape( rawurldecode( $_POST[ 'twitterdescription' ] ) ) . "%' AND ";
		$where = trim( $where ) . " 1=1";
		$where = str_replace( '%', '%%', $where );
		$tweets = tweet_tweet_search_tweets( "SELECT * FROM %s WHERE $where ORDER by tid DESC" );
	} else {
		$tweets = $wpdb->get_results( "SELECT * FROM {$wpdb->twitterarchives} ORDER by tid DESC LIMIT $start,$twitter_limit" );
	}
	if( $tweets ) {
		echo "<table border=0 cellspacing=0 style='padding: 0px; margin: 0px;'>";
		$bg = '';
		$count = 0;
		foreach( $tweets as $tweet ) {
			if( $_POST[ 'twittertweets' ] == 'replies' ) {
				$bg = $bg == '' ? ' style="background: #efe"' : '';
			} else {
				$bg = $bg == '' ? ' style="background: #eEf1fF"' : '';
			}
			if( substr( $tweet->username, -4 ) == ' (d)' || substr( $tweet->username, 0, 4 ) == '(d) ') {
				$bg = ' style="background: #ffe"';
				$link = "http://twitter.com/" . trim( str_replace( '(d)', '', $tweet->username ) );
			} else {
				$link = "http://twitter.com/" . $tweet->username . "/statuses/" . $tweet->tid;
			}
			$description = ereg_replace("[[:alpha:]]+://[^<>[:space:]]+[[:alnum:]/]","<a href=\"\\0\">\\0</a>", $tweet->description);
			$description = ereg_replace("@([^<>[:space:]]+[[:alnum:]])","<a href=\"http://twitter.com/\\1\">@\\1</a>", $description);
			$tdate = date("H:i:s", ( strtotime( $tweet->pubdate ) + ( get_settings('gmt_offset') * 3600 ) ) );
			$username = $tweet->username;
			if( strpos( $tweet->username, ' (d)' ) ) {
				$username = str_replace( ' (d)', '', $username );
				$username = 'From:&nbsp;' . $username;
			} elseif( false !== strpos( $tweet->username, '(d) ' ) )  {
				$username = str_replace( '(d) ', 'To:&nbsp;', $username );
			}
			echo "<tr{$bg}><td style='border-bottom: 1px solid #ddd; margin: 2px;' valign='top'><a href='{$link}'>{$username}</a>:<br />@ $tdate</td><td style='border-bottom: 1px solid #ddd; padding-top: 2px; padding-bottom: 2px;' valign='top'> {$description}</td></tr>";
		}
		echo "</table>";
	} elseif( $_POST[ 'twittertweets' ] == 'search' ) {
		?><p>No tweets found for that search.</p><?php
	} else {
		?><p>No tweets archived yet. Make sure your theme has the "wp_footer" action. &lt;?php do_action('wp_footer'); ?&gt; must be in the footer.php or in some other theme file.</p><?php
	}
	if( $_POST[ 'action' ] == 'twitterupdate' )
		die();
}
add_action( 'wp_ajax_twitterupdate', 'twitter_update_ajax' );

function my_tweets( $limit = 5, $before = '<li>', $after='</li>', $random = 0 ) {
	$tweets = get_my_tweets( $limit, $random );
	foreach( $tweets as $t ) {
		$description = ereg_replace("[[:alpha:]]+://[^<>[:space:]]+[[:alnum:]/]","<a href=\"\\0\">\\0</a>", $t->description);
		echo "{$before}{$description} <a href='http://twitter.com/{$t->username}/statuses/{$t->tid}/'>#</a>{$after}";
	}
}

function get_my_tweets( $limit = 5, $random = 0 ) {
	global $wpdb;
	$options = get_option( 'tweet_tweet' );
	if( !is_array( $options ) )
		return array();
	$tweets = wp_cache_get( "mytweets", 'widget' );
	$tweets = false;
	if( $random ) {
		$orderby = 'RAND()';
	} else {
		$orderby = 'tid';
	}
	if( !is_array( $tweets ) ) {
		$tweets = $wpdb->get_results( "SELECT * FROM {$wpdb->twitterarchives} WHERE username = '{$options[ 'username' ]}' ORDER BY $orderby DESC LIMIT 0, $limit" );
		wp_cache_add( 'mytweets', $tweets, 'widget' );
	}

	return $tweets;
}

// oAuth code from Twitter Tools
function tweet_tweet_oauth_test() {
	global $aktt;
	return ( tweet_tweet_oauth_credentials_to_hash() == get_option('tweet_tweet_oauth_hash') );
}

function tweet_tweet_oauth_credentials_to_hash() {
	$tweet_tweet_oauth = get_option( 'tweet_tweet_oauth' );
	return md5( $tweet_tweet_oauth[ 'app_consumer_key' ] . $tweet_tweet_oauth[ 'app_consumer_secret' ] . $tweet_tweet_oauth[ 'oauth_token' ] . $tweet_tweet_oauth[ 'oauth_token_secret' ] );
}

function tweet_tweet_oauth_connection( $tweet_tweet_oauth = false ) {
	if ( $tweet_tweet_oauth == false )
		$tweet_tweet_oauth = get_option( 'tweet_tweet_oauth' );

	if ( !empty($tweet_tweet_oauth[ 'app_consumer_key' ]) && !empty($tweet_tweet_oauth[ 'app_consumer_secret' ]) && !empty($tweet_tweet_oauth[ 'oauth_token' ]) && !empty($tweet_tweet_oauth[ 'oauth_token_secret' ]) ) {	
		require_once('twitteroauth.php');
		$connection = new TwitterOAuth(
			$tweet_tweet_oauth[ 'app_consumer_key' ], 
			$tweet_tweet_oauth[ 'app_consumer_secret' ], 
			$tweet_tweet_oauth[ 'oauth_token' ], 
			$tweet_tweet_oauth[ 'oauth_token_secret' ]
		);
		$connection->useragent = 'Tweet Tweet http://ocaoimh.ie/';
		return $connection;
	}
	else {
		return false;
	}
}


?>
