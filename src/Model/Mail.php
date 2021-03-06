<?php

/**
 * @file src/Model/Mail.php
 */
namespace Friendica\Model;

use Friendica\Core\L10n;
use Friendica\Core\Logger;
use Friendica\Core\System;
use Friendica\Core\Worker;
use Friendica\Model\Item;
use Friendica\Model\Photo;
use Friendica\Database\DBA;
use Friendica\Network\Probe;
use Friendica\Util\DateTimeFormat;
use Friendica\Worker\Delivery;

/**
 * Class to handle private messages
 */
class Mail
{
	/**
	 * Insert received private message
	 *
	 * @param array $msg
	 * @return int|boolean Message ID or false on error
	 */
	public static function insert($msg)
	{
		$user = User::getById($msg['uid']);

		if (!isset($msg['reply'])) {
			$msg['reply'] = DBA::exists('mail', ['parent-uri' => $msg['parent-uri']]);
		}

		if (empty($msg['convid'])) {
			$mail = DBA::selectFirst('mail', ['convid'], ["`convid` != 0 AND `parent-uri` = ?", $msg['parent-uri']]);
			if (DBA::isResult($mail)) {
				$msg['convid'] = $mail['convid'];
			}
		}

		if (empty($msg['guid'])) {
			$host = parse_url($msg['from-url'], PHP_URL_HOST);
			$msg['guid'] = Item::guidFromUri($msg['uri'], $host);
		}

		$msg['created'] = (!empty($msg['created']) ? DateTimeFormat::utc($msg['created']) : DateTimeFormat::utcNow());

		DBA::lock('mail');

		if (DBA::exists('mail', ['uri' => $msg['uri'], 'uid' => $msg['uid']])) {
			DBA::unlock();
			Logger::info('duplicate message already delivered.');
			return false;
		}

		DBA::insert('mail', $msg);

		$msg['id'] = DBA::lastInsertId();

		DBA::unlock();

		if (!empty($msg['convid'])) {
			DBA::update('conv', ['updated' => DateTimeFormat::utcNow()], ['id' => $msg['convid']]);
		}

		// send notifications.
		$notif_params = [
			'type' => NOTIFY_MAIL,
			'notify_flags' => $user['notify-flags'],
			'language' => $user['language'],
			'to_name' => $user['username'],
			'to_email' => $user['email'],
			'uid' => $user['uid'],
			'item' => $msg,
			'parent' => 0,
			'source_name' => $msg['from-name'],
			'source_link' => $msg['from-url'],
			'source_photo' => $msg['from-photo'],
			'verb' => ACTIVITY_POST,
			'otype' => 'mail'
		];

		notification($notif_params);

		Logger::info('Mail is processed, notification was sent.', ['id' => $msg['id'], 'uri' => $msg['uri']]);

		return $msg['id'];
	}

	/**
	 * Send private message
	 *
	 * @param integer $recipient recipient id, default 0
	 * @param string  $body      message body, default empty
	 * @param string  $subject   message subject, default empty
	 * @param string  $replyto   reply to
	 * @return int
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	public static function send($recipient = 0, $body = '', $subject = '', $replyto = '')
	{
		$a = \get_app();

		if (!$recipient) {
			return -1;
		}

		if (!strlen($subject)) {
			$subject = L10n::t('[no subject]');
		}

		$me = DBA::selectFirst('contact', [], ['uid' => local_user(), 'self' => true]);
		$contact = DBA::selectFirst('contact', [], ['id' => $recipient, 'uid' => local_user()]);

		if (!(count($me) && (count($contact)))) {
			return -2;
		}

		Photo::setPermissionFromBody($body, local_user(), $me['id'],  '<' . $contact['id'] . '>', '', '', '');

		$guid = System::createUUID();
		$uri = Item::newURI(local_user(), $guid);

		$convid = 0;
		$reply = false;

		// look for any existing conversation structure

		if (strlen($replyto)) {
			$reply = true;
			$condition = ["`uid` = ? AND (`uri` = ? OR `parent-uri` = ?)",
				local_user(), $replyto, $replyto];
			$mail = DBA::selectFirst('mail', ['convid'], $condition);
			if (DBA::isResult($mail)) {
				$convid = $mail['convid'];
			}
		}

		$convuri = '';
		if (!$convid) {
			// create a new conversation
			$recip_host = substr($contact['url'], strpos($contact['url'], '://') + 3);
			$recip_host = substr($recip_host, 0, strpos($recip_host, '/'));

			$recip_handle = (($contact['addr']) ? $contact['addr'] : $contact['nick'] . '@' . $recip_host);
			$sender_handle = $a->user['nickname'] . '@' . substr(System::baseUrl(), strpos(System::baseUrl(), '://') + 3);

			$conv_guid = System::createUUID();
			$convuri = $recip_handle . ':' . $conv_guid;

			$handles = $recip_handle . ';' . $sender_handle;

			$fields = ['uid' => local_user(), 'guid' => $conv_guid, 'creator' => $sender_handle,
				'created' => DateTimeFormat::utcNow(), 'updated' => DateTimeFormat::utcNow(),
				'subject' => $subject, 'recips' => $handles];
			if (DBA::insert('conv', $fields)) {
				$convid = DBA::lastInsertId();
			}
		}

		if (!$convid) {
			Logger::log('send message: conversation not found.');
			return -4;
		}

		if (!strlen($replyto)) {
			$replyto = $convuri;
		}

		$post_id = null;
		$success = DBA::insert(
			'mail',
			[
				'uid' => local_user(),
				'guid' => $guid,
				'convid' => $convid,
				'from-name' => $me['name'],
				'from-photo' => $me['thumb'],
				'from-url' => $me['url'],
				'contact-id' => $recipient,
				'title' => $subject,
				'body' => $body,
				'seen' => 1,
				'reply' => $reply,
				'replied' => 0,
				'uri' => $uri,
				'parent-uri' => $replyto,
				'created' => DateTimeFormat::utcNow()
			]
		);

		if ($success) {
			$post_id = DBA::lastInsertId();
		}

		/**
		 *
		 * When a photo was uploaded into the message using the (profile wall) ajax
		 * uploader, The permissions are initially set to disallow anybody but the
		 * owner from seeing it. This is because the permissions may not yet have been
		 * set for the post. If it's private, the photo permissions should be set
		 * appropriately. But we didn't know the final permissions on the post until
		 * now. So now we'll look for links of uploaded messages that are in the
		 * post and set them to the same permissions as the post itself.
		 *
		 */
		$match = null;
		if (preg_match_all("/\[img\](.*?)\[\/img\]/", $body, $match)) {
			$images = $match[1];
			if (count($images)) {
				foreach ($images as $image) {
					if (!stristr($image, System::baseUrl() . '/photo/')) {
						continue;
					}
					$image_uri = substr($image, strrpos($image, '/') + 1);
					$image_uri = substr($image_uri, 0, strpos($image_uri, '-'));
					Photo::update(['allow-cid' => '<' . $recipient . '>'], ['resource-id' => $image_uri, 'album' => 'Wall Photos', 'uid' => local_user()]);
				}
			}
		}

		if ($post_id) {
			Worker::add(PRIORITY_HIGH, "Notifier", Delivery::MAIL, $post_id);
			return intval($post_id);
		} else {
			return -3;
		}
	}

	/**
	 * @param array  $recipient recipient, default empty
	 * @param string $body      message body, default empty
	 * @param string $subject   message subject, default empty
	 * @param string $replyto   reply to, default empty
	 * @return int
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 */
	public static function sendWall(array $recipient = [], $body = '', $subject = '', $replyto = '')
	{
		if (!$recipient) {
			return -1;
		}

		if (!strlen($subject)) {
			$subject = L10n::t('[no subject]');
		}

		$guid = System::createUUID();
		$uri = Item::newURI(local_user(), $guid);

		$me = Probe::uri($replyto);

		if (!$me['name']) {
			return -2;
		}

		$conv_guid = System::createUUID();

		$recip_handle = $recipient['nickname'] . '@' . substr(System::baseUrl(), strpos(System::baseUrl(), '://') + 3);

		$sender_nick = basename($replyto);
		$sender_host = substr($replyto, strpos($replyto, '://') + 3);
		$sender_host = substr($sender_host, 0, strpos($sender_host, '/'));
		$sender_handle = $sender_nick . '@' . $sender_host;

		$handles = $recip_handle . ';' . $sender_handle;

		$convid = null;
		$fields = ['uid' => $recipient['uid'], 'guid' => $conv_guid, 'creator' => $sender_handle,
			'created' => DateTimeFormat::utcNow(), 'updated' => DateTimeFormat::utcNow(),
			'subject' => $subject, 'recips' => $handles];
		if (DBA::insert('conv', $fields)) {
			$convid = DBA::lastInsertId();
		}

		if (!$convid) {
			Logger::log('send message: conversation not found.');
			return -4;
		}

		DBA::insert(
			'mail',
			[
				'uid' => $recipient['uid'],
				'guid' => $guid,
				'convid' => $convid,
				'from-name' => $me['name'],
				'from-photo' => $me['photo'],
				'from-url' => $me['url'],
				'contact-id' => 0,
				'title' => $subject,
				'body' => $body,
				'seen' => 0,
				'reply' => 0,
				'replied' => 0,
				'uri' => $uri,
				'parent-uri' => $replyto,
				'created' => DateTimeFormat::utcNow(),
				'unknown' => 1
			]
		);

		return 0;
	}
}
