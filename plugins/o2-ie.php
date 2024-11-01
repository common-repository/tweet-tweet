<?php

/* O2 Ireland Twitter notification plugin, with thanks to Enda at http://contrar.ie/ */

function o_tweets( $details ) {
	global $ch, $o_cfid, $o_cftoken;
	$options = get_option( 'tweet_tweet' );
	if( $options[ 'o2' ] == 0 )
		return;

	$o_username = '08';
	$o_tweet_dest = '';
	$o_password = '';
	if( is_array( $options ) )
		extract( $options );
	if( $o_username == '08' && $o_password == '' )
		return;

	if( !isset( $ch ) ) {
		$ch = curl_init( );
		curl_setopt( $ch, CURLOPT_HEADER, true );
		if( $options[ 'UA' ] ) {
			curl_setopt( $ch, CURLOPT_USERAGENT, $options[ 'UA' ] );
		} else {
			curl_setopt( $ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows; U; Windows NT 5.1; hu; rv:1.9.0.1) Gecko/2008070208 Firefox/3.0.1" );
		}
		curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_COOKIEJAR, 'cookie.txt');
		curl_setopt( $ch, CURLOPT_COOKIEFILE, 'cookie.txt');    

		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); 
		$text = o_request( 'post', 'https://www.o2online.ie/amserver/UI/Login', 'https://www.o2online.ie/amserver/UI/Login', array('IDToken1=' . $o_username, 'IDToken2=' . $o_password, 'Go=Go', 'org=o2ext', 'gx_charset=UTF-8') );
		#.cg Just assume login was OK? (Probably should add a check for 302, or known text in the 200)
	}
	sleep( 5*mt_rand( 2, 3 ) );

	# Cathal Garvey @ http://cgarvey.ie/ fixed the o2.ie curl scripts after their Nov 8th 2008 redesign.
	#.cg Get request to initial WebText page (after login), so we can get Session ID (not the cookies-based one, the one that's set in the content, and used for subsequent requests)
	# Parse that response text, assuming there is some, for the known text of that ID
	$text = o_request( 'get', 'http://messaging.o2online.ie/ssomanager.osp?APIID=AUTH-WEBSSO', 'http://www.o2online.ie/wps/wcm/connect/O2/Logged+in/LoginCheck', '' );
	if( ereg( "var GLOBAL_SESSION_ID = '([^']*)';", $text, $matches ) ) {
		$sessionID = $matches[1];
	}

	#.cg Only proceed if session ID present (o2 site is often flakey, so this might not be the case)
	if( $sessionID != "" ) {
		$msg = urlencode( $details[ 'username' ] . ': ' . $details[ 'description' ] );
		$text = o_request( 'post', "http://messaging.o2online.ie/smscenter_send.osp", "Referer: http://messaging.o2online.ie/infocenter.osp?SID=" . $sessionID, "SID=" . $sessionID . "&MsgContentID=-1&FlagDLR=1&FolderID=0&SMSToNormalized=&FID=&RURL=o2om_smscenter_new.osp%3FSID%3D" . $sessionID . "%26MsgContentID%3D-1&SMSTo=%2B" . $o_tweet_dest . "&SMSText=" . $msg );
	}
}

function o_reply_tweets( $details ) {
	$options = get_option( 'tweet_tweet' );
	if( !$options[ 'here' ] && $options[ 'o_replies' ] )
		o_tweets( $details );
}
add_action( 'tweet_tweet_reply', 'o_reply_tweets' );

function o_direct_tweets( $details ) {
	$details[ 'username' ] = 'd ' . $details[ 'username' ];
	$options = get_option( 'tweet_tweet' );
	if( !$options[ 'here' ] && $options[ 'o_direct' ] )
		o_tweets( $details );
}
add_action( 'tweet_tweet_direct', 'o_direct_tweets' );

function o_request( $method, $url,$refer, $vars ) {
	global $ch;
	// if the $vars are in an array then turn them into a usable string
	if( is_array( $vars ) ):
		$vars = implode( '&', $vars );
	endif;

	// setup the url to post / get from / to
	curl_setopt( $ch, CURLOPT_URL, $url );
	// the actual post bit
	if ( strtolower( $method ) == 'post' ) :
		curl_setopt( $ch, CURLOPT_POST, true );
	curl_setopt( $ch, CURLOPT_POSTFIELDS, $vars );
	endif;
	if( $referer != '' )
		curl_setopt($ch, CURLOPT_REFERER, $refer ); 
	// return data
	$r = curl_exec( $ch );
	return $r;
}

function o_tweet_config_page() {
	$options = get_option( 'tweet_tweet' );
	$o_username = '08';
	$o_tweet_dest = '';
	$o_password = '';
	$o_replies = 1;
	$o_direct = 1;
	$o2 = 0;
	if( is_array( $options ) )
		extract( $options );
	?>
	<p><label><input id="o2" name="o2" type='checkbox' value="1" <?php if( $o2 ) echo "checked='checked'"; ?> /> <strong>O2.ie</strong></label></p>
	<?php if( $o2 == 0 )
		return;
	?>
	<div style='margin-left: 20px;'><p>Irish O2 customers can receive text notifications when they get Twitter replies or direct messages.<p>
	<label>Phone: <input id="o_username" name="o_username" type="text" size="15" maxlength="20" value="<?php echo $o_username ?>" /></label><br />
	<label>PIN: <input id="o_password" name="o_password" type="password" size="15" maxlength="15" value="<?php echo $o_password ?>" /></label><br />
	<label><input id="o_replies" name="o_replies" type='checkbox' value="1" <?php if( $o_replies ) echo "checked='checked'"; ?> /> Replies</label><br />
	<label><input id="o_direct" name="o_direct" type='checkbox' value="1" <?php if( $o_direct ) echo "checked='checked'"; ?> /> Direct</label><br />
	<p>Where would you like to send your tweets? Leave blank to use the phone number above.</p>
	<p><label>Send tweets to: <input id="o_tweet_dest" name="o_tweet_dest" type="text" size="15" maxlength="20" value="<?php echo $o_tweet_dest ?>" /></label></p></div>
	<p><label><input type='checkbox' name='test' value='1'> Send Test SMS</label></p>
	<?php
}
add_action( 'tweet_tweet_config_page', 'o_tweet_config_page' );

function o_tweet_post( $options ) {
	$checkboxes = array( 'o2', 'o_direct', 'o_replies' );
	reset( $checkboxes );
	foreach( $checkboxes as $c ) {
		if( $_POST[ $c ] ) {
			$options[ $c ] = 1;
		} else {
			$options[ $c ] = 0;
		}
	}

	if( $_POST[ 'o_username' ] == '' || $_POST[ 'o_username' ] == '08' || $_POST[ 'o_password' ] == '' )
		return $options;

	if( $_POST[ 'o_username' ] != '08' && is_numeric( $_POST[ 'o_username' ] ) ) {
		$options[ 'o_username' ] = $_POST[ 'o_username' ];
		if( $_POST[ 'o_tweet_dest' ] == '' ) {
			$options[ 'o_tweet_dest' ] = $_POST[ 'o_username' ];
		} elseif( is_numeric( $_POST[ 'o_tweet_dest' ] ) )
			$options[ 'o_tweet_dest' ] = $_POST[ 'o_tweet_dest' ];
	}
	if( $_POST[ 'o_password' ] != '' )
		$options[ 'o_password' ] = $_POST[ 'o_password' ];

	$option[ 'UA' ] = $_SERVER[ 'HTTP_USER_AGENT' ];
	
	if( $_POST[ 'test' ] ) {
		o_tweets( array( 'username' => 'test', 'description' => 'This is a test message' ) );
	}

	return $options;
}
add_filter( 'tweet_tweet_options', 'o_tweet_post' );
?>
