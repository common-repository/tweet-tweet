<?php
/*
This Tweet Tweet plugin will send you an sms text message when you
receive a Twitter reply or direct message.
It uses the free web texts on the meteor.ie website.

Copyright Donncha O Caoimh, http://ocaoimh.ie/tweet-tweet/
*/

function meteor_tweets( $details ) {
	global $ch, $m_cfid, $m_cftoken;
	$options = get_option( 'tweet_tweet' );
	if( $options[ 'meteor' ] == 0 )
		return;

	$m_username = '08';
	$m_tweet_dest = '';
	$m_password = '';
	if( is_array( $options ) )
		extract( $options );
	if( $m_username == '08' && $m_password == '' )
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
		$text = meteor_request( 'post', 'https://www.mymeteor.ie/go/mymeteor-login-manager', 'https://www.mymeteor.ie/', array('username=' . $m_username, 'userpass=' . $m_password, 'returnTo=', 'dologin=') );
		preg_match( '/CFID=(.*);expires/', $text, $m );
		$m_cfid = $m[1];
		preg_match( '/CFTOKEN=(.*);expires/', $text, $m );
		$m_cftoken = $m[1];
		if( $m_cftoken == '' || $m_cfid == '' ) {
			return;
		}
	}
	sleep( 5 );
	meteor_request( 'post', 'https://www.mymeteor.ie/mymeteorapi/index.cfm?event=smsAjax&func=addEnteredMsisdns', 'Referer: https://www.mymeteor.ie/go/freewebtext', 'ajaxRequest=addEnteredMSISDNs&remove=-&add=0%7C' . $m_tweet_dest );
	sleep( 2*mt_rand( 2, 5 ) );
	meteor_request( 'post', 'https://www.mymeteor.ie/mymeteorapi/index.cfm?event=smsAjax&func=sendSMS&CFID=' . $m_cfid . '&CFTOKEN=' . $m_cftoken, 'Referer: https://www.mymeteor.ie/go/freewebtext', 'ajaxRequest=sendSMS&messageText=' . urlencode( $details[ 'username' ] . ': ' . $details[ 'description' ] ) );
}

function meteor_reply_tweets( $details ) {
	$options = get_option( 'tweet_tweet' );
	if( !$options[ 'here' ] && $options[ 'm_replies' ] )
		meteor_tweets( $details );
}
add_action( 'tweet_tweet_reply', 'meteor_reply_tweets' );

function meteor_direct_tweets( $details ) {
	$details[ 'username' ] = 'd ' . $details[ 'username' ];
	$options = get_option( 'tweet_tweet' );
	if( !$options[ 'here' ] && $options[ 'm_direct' ] )
		meteor_tweets( $details );
}
add_action( 'tweet_tweet_direct', 'meteor_direct_tweets' );

function meteor_request( $method, $url,$refer, $vars ) {
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

function meteor_tweet_config_page() {
	$options = get_option( 'tweet_tweet' );
	$m_username = '08';
	$m_tweet_dest = '';
	$m_password = '';
	$m_replies = 1;
	$m_direct = 1;
	$meteor = 0;
	if( is_array( $options ) )
		extract( $options );
	?>
	<p><label><input id="meteor" name="meteor" type='checkbox' value="1" <?php if( $meteor ) echo "checked='checked'"; ?> /> <strong>Meteor.ie</strong></label></p>
	<?php if( $meteor == 0 )
		return;
	?>
	<div style='margin-left: 20px;'><p>Irish Meteor customers can receive text notifications when they get Twitter replies or direct messages.<p>
	<label>Phone: <input id="m_username" name="m_username" type="text" size="15" maxlength="20" value="<?php echo $m_username ?>" /></label><br />
	<label>PIN: <input id="m_password" name="m_password" type="password" size="6" maxlength="6" value="<?php echo $m_password ?>" /></label><br />
	<label><input id="m_replies" name="m_replies" type='checkbox' value="1" <?php if( $m_replies ) echo "checked='checked'"; ?> /> Replies</label><br />
	<label><input id="m_direct" name="m_direct" type='checkbox' value="1" <?php if( $m_direct ) echo "checked='checked'"; ?> /> Direct</label><br />
	<p>Where would you like to send your tweets? Leave blank to use the phone number above.</p>
	<p><label>Send tweets to: <input id="m_tweet_dest" name="m_tweet_dest" type="text" size="15" maxlength="20" value="<?php echo $m_tweet_dest ?>" /></label></p></div>
	<p><label><input type='checkbox' name='test' value='1'> Send Test SMS</label></p>
	<?php
}
add_action( 'tweet_tweet_config_page', 'meteor_tweet_config_page' );

function meteor_tweet_post( $options ) {
	$checkboxes = array( 'meteor', 'm_direct', 'm_replies' );
	reset( $checkboxes );
	foreach( $checkboxes as $c ) {
		if( $_POST[ $c ] ) {
			$options[ $c ] = 1;
		} else {
			$options[ $c ] = 0;
		}
	}

	if( $_POST[ 'm_username' ] == '' || $_POST[ 'm_username' ] == '08' || $_POST[ 'm_password' ] == '' )
		return $options;

	if( $_POST[ 'm_username' ] != '08' && is_numeric( $_POST[ 'm_username' ] ) ) {
		$options[ 'm_username' ] = $_POST[ 'm_username' ];
		if( $_POST[ 'm_tweet_dest' ] == '' ) {
			$options[ 'm_tweet_dest' ] = $_POST[ 'm_username' ];
		} elseif( is_numeric( $_POST[ 'm_tweet_dest' ] ) )
			$options[ 'm_tweet_dest' ] = $_POST[ 'm_tweet_dest' ];
	}
	if( $_POST[ 'm_password' ] != '' && is_numeric( $_POST[ 'm_password' ] ) )
		$options[ 'm_password' ] = $_POST[ 'm_password' ];

	$option[ 'UA' ] = $_SERVER[ 'HTTP_USER_AGENT' ];
	
	if( $_POST[ 'test' ] ) {
		meteor_tweets( array( 'username' => 'test', 'description' => 'This is a test message' ) );
	}

	return $options;
}
add_filter( 'tweet_tweet_options', 'meteor_tweet_post' );
?>
