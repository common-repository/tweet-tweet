=== Tweet Tweet ===
Contributors: donncha, automattic
Tags: twitter, tweets
Tested up to: 3.4.2
Stable tag: 0.5.7
Requires at least: 2.9.2

Archive your Twitter conversions in your database, and use your free web texts to receive sms notifications.

== Description ==
Twitter.com has become a huge success by making it easy for people to converse in short 140 character messages. One thing that always bothered me was that my tweets would be lost if Twitter ever went bust, or they suffered some catastrophic failure where data was lost. It can happen. That's why I wrote this plugin.

This plugin archives your tweets, and the tweets of those you follow in your database. It also stores replies from other people, as well as direct messages.

Older tweets can be stored in archive tables. When the number of tweets reaches a pre-defined limit the table is renamed and a timestamp added to the table name. The original table is then created again. I rotate my tweet archives when they hit 100,000 records. That helps to improve server performance because MySQL doesn't have to search through as many records. 99% of the time you'll only want to know about the most recent tweets anyway.
Tables are checked once a day. This is disabled by default.

Recently Twitter stopped sending sms notifications from their UK number which has of course annoyed a lot of European users. Tweet Tweet has hooks that allow developers to write sms notification plugins. Included with version 0.3 are plugins for Meteor and Vodafone customers in Ireland. Thanks to [Jason Roe](http://www.jason-roe.com/blog/) for contributing the vodafone.ie plugin. If you're interested in adding a plugin for your mobile phone company, feel free to use the existing plugins as a base to work from.

Display tweets on your blog using the my_tweets() or get_my_tweets() functions. See tweet-tweet.php for usage.

See the [Tweet Tweet homepage](http://ocaoimh.ie/tweet-tweet/) for further information.

== Changelog ==

= 0.5.7 =
Add the ability to search all the tweets archived, across database tables.
Store Twitter profiles locally in a database table.

= 0.5.6 =
Added OAuth support using code from Twitter Tools by Alex King, and code by Abraham Williams and oauth.net
Removed "since" parameter when downloadng tweets so the plugin grabs as many as it can.
Add warnings to increase "update interval" if less than 100 tweets per go are new to avoid wasting API requests.
Added "Refill" functions on admin page to poplate tweets of different types (own/user/home)

= 0.5.5 =
Archive tweets to avoid problems and slow downs with large database tables.
Added note about my_tweets() and get_my_tweets() functions.

= 0.5.4 =
Twitter IDs are bigger than PHP's integer. Stop using intval().

= 0.5.3 =
Use since_id to get last tweets, Twitter updated their API.
Move delete rss cache files code up.
Added Here/Away function so sms texts aren't sent when I'm "here"
Fix to O2 Ireland sms text sender

== Installation ==
1. Unzip and upload the tweet-tweet directory to your plugins folder.
2. Activate the plugin on your Plugins page.
3. Configure the plugin by registering your install at Twitter and going through the OAuth process.

= Troubleshooting =

If things don't work when you installed the plugin here are a few things to check:
1. Wait. The plugin polls Twitter at most every 90 seconds. If you're not seeing any tweets, Twitter might not have been polled yet.
2. Double check that you have a registered application in your Twitter account in Settings
3. Check your access logs for requests for "wp-cron.php".
4. Anything in your php error_log?
