<?php
/*
This Tweet Tweet plugin will send you an sms text message when you
receive a Twitter reply or direct message.
It uses the free web texts on the Vodafone.ie website.

Copyright Donncha O Caoimh, http://ocaoimh.ie/tweet-tweet/

Adapted for use with Vodafone.ie by Jason Roe. http://www.jason-roe.com/blog/
*/

function vodap_tweets( $details ) {
	global $v_ch, $v_cfid, $v_cftoken;
	$options = get_option( 'tweet_tweet' );
	if( $options[ 'vodap' ] == 0 )
		return;

	$v_username = '08';
	$v_tweet_dest = '';
	$v_password = '';
	if( is_array( $options ) )
		extract( $options );
	if( $v_username == '08' && $v_password == '' )
		return;

	if( !isset( $v_ch ) ) {
		$v_ch = curl_init( );
		curl_setopt( $v_ch, CURLOPT_HEADER, true );
		if( $options[ 'UA' ] ) {
			curl_setopt( $v_ch, CURLOPT_USERAGENT, $options[ 'UA' ] );
		} else {
			curl_setopt( $v_ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows; U; Windows NT 5.1; hu; rv:1.9.0.1) Gecko/2008070208 Firefox/3.0.1" );
		}
		curl_setopt( $v_ch, CURLOPT_FOLLOWLOCATION, true );
		curl_setopt( $v_ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $v_ch, CURLOPT_COOKIEJAR, 'cookie.txt');
		curl_setopt( $v_ch, CURLOPT_COOKIEFILE, 'cookie.txt');    

		curl_setopt($v_ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($v_ch, CURLOPT_SSL_VERIFYHOST, false); 

		$v_text = vodap_request( 'post', 'https://www.vodafone.ie/myv/services/login/Login.shtml', 'https://www.vodafone.ie/myv/services/login/login.jsp?redirect=/myv/index.jsp', 'username='.urlencode($v_username).'&password='.urlencode($v_password).'&keeplogon=on&redirect=/myv/messaging/webtext/' );

		preg_match('/"org.apache.struts.taglib.html.TOKEN" value="(.*)"/',$v_text,$m_v);

		$v_cftoken = $m_v[1];
		if( $v_cftoken == '' ) {
			return;
		}
	}
	sleep( 5 );

	$v_text2 = vodap_request( 'post', 'https://www.vodafone.ie/myv/messaging/webtext/Process.shtml', 'https://www.vodafone.ie/myv/messaging/webtext/',  'org.apache.struts.taglib.html.TOKEN='.urlencode($v_cftoken).'&message='. urlencode( $details[ 'username' ] . ': ' . $details[ 'description' ] ).'&recipients[0]='.urlencode($v_tweet_dest).'&recipients[1]=&recipients[2]=&recipients[3]=&recipients[4]=&futuredate=false&redirect='.urlencode("/myv/index.js"));
}

function vodap_reply_tweets( $details ) {
	$options = get_option( 'tweet_tweet' );
	if( !$options[ 'here' ] && $options[ 'v_replies' ] )
		vodap_tweets( $details );
}
add_action( 'tweet_tweet_reply', 'vodap_reply_tweets' );

function vodap_direct_tweets( $details ) {
	$details[ 'username' ] = 'd ' . $details[ 'username' ];
	$options = get_option( 'tweet_tweet' );
	if( !$options[ 'here' ] && $options[ 'v_direct' ] )
		vodap_tweets( $details );
}
add_action( 'tweet_tweet_direct', 'vodap_direct_tweets' );

function vodap_request( $method, $url,$refer, $vars ) {
	global $v_ch;
	// if the $vars are in an array then turn them into a usable string
	if( is_array( $vars ) ):
		$vars = implode( '&', $vars );
	endif;

	// setup the url to post / get from / to
	curl_setopt( $v_ch, CURLOPT_URL, $url );
	// the actual post bit
	if ( strtolower( $method ) == 'post' ) :
		curl_setopt( $v_ch, CURLOPT_POST, true );
	curl_setopt( $v_ch, CURLOPT_POSTFIELDS, $vars );
	endif;
	if( $referer != '' )
		curl_setopt($v_ch, CURLOPT_REFERER, $refer ); 
	// return data
	$r = curl_exec( $v_ch );
	return $r;
}

function vodap_tweet_config_page() {
	$options = get_option( 'tweet_tweet' );
	$v_username = '08';
	$v_tweet_dest = '';
	$v_password = '';
	$v_replies = 1;
	$v_direct = 1;
	$vodap = 0;
	if( is_array( $options ) )
		extract( $options );
	?>
	<p><label><input id="vodap" name="vodap" type='checkbox' value="1" <?php if( $vodap ) echo "checked='checked'"; ?> /> <strong>Vodafone.ie</strong></label></p>
	<?php if( $vodap == 0 )
		return;
	?>
	<div style='margin-left: 20px;'><p>Irish Vodafone customers can receive text notifications when they get Twitter replies or direct messages.</p>
	<label>Phone: <input id="v_username" name="v_username" type="text" size="15" maxlength="20" value="<?php echo $v_username ?>" /></label><br />
	<label>Pass: <input id="v_password" name="v_password" type="password" size="6" maxlength="6" value="<?php echo $v_password ?>" /></label><br />
	<label><input id="v_replies" name="v_replies" type='checkbox' value="1" <?php if( $v_replies ) echo "checked='checked'"; ?> /> Replies</label><br />
	<label><input id="v_direct" name="v_direct" type='checkbox' value="1" <?php if( $v_direct ) echo "checked='checked'"; ?> /> Direct</label><br />
	<p>Where would you like to send your tweets? Leave blank to use the phone number above.</p>
	<p><label>Send tweets to: <input id="v_tweet_dest" name="v_tweet_dest" type="text" size="15" maxlength="20" value="<?php echo $v_tweet_dest ?>" /></label></p></div>
	<p><label><input type='checkbox' name='test' value='1'> Send Test SMS</label></p>
	<?php
}
add_action( 'tweet_tweet_config_page', 'vodap_tweet_config_page' );

function vodap_tweet_post( $options ) {
	$v_checkboxes = array( 'vodap', 'v_direct', 'v_replies' );
	reset( $v_checkboxes );
	foreach( $v_checkboxes as $c ) {
		if( $_POST[ $c ] ) {
			$options[ $c ] = 1;
		} else {
			$options[ $c ] = 0;
		}
	}

	if( $_POST[ 'v_username' ] == '' || $_POST[ 'v_username' ] == '08' || $_POST[ 'v_password' ] == '' )
		return $options;

	if( $_POST[ 'v_username' ] != '08' && is_numeric( $_POST[ 'v_username' ] ) ) {
		$options[ 'v_username' ] = $_POST[ 'v_username' ];
		if( $_POST[ 'v_tweet_dest' ] == '' ) {
			$options[ 'v_tweet_dest' ] = $_POST[ 'v_username' ];
		} elseif( is_numeric( $_POST[ 'v_tweet_dest' ] ) )
			$options[ 'v_tweet_dest' ] = $_POST[ 'v_tweet_dest' ];
	}
	if( $_POST[ 'v_password' ] != '')
		$options[ 'v_password' ] = $_POST[ 'v_password' ];

	$option[ 'UA' ] = $_SERVER[ 'HTTP_USER_AGENT' ];
	
	if( $_POST[ 'test' ] ) {
		vodap_tweets( array( 'username' => 'test', 'description' => 'This is a test message' ) );
	}

	return $options;
}
add_filter( 'tweet_tweet_options', 'vodap_tweet_post' );
?>
