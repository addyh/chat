<?php
session_start();

/* DATABASE SETTINGS */
$db_host = "";
$db_name = "";
$db_user = "";
$db_pass = "";
$page_title = "";
$page_header = "";
$page_status = "";
include_once __DIR__ . '/config.php';

try {
	$pdo = new PDO(
		"mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
		$db_user,
		$db_pass,
		[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
	);
} catch (PDOException $e) {
	die("Database connection failed.");
}

/* CREATE MESSAGE TABLE */
$pdo->exec("
CREATE TABLE IF NOT EXISTS chat_messages (
	id INT AUTO_INCREMENT PRIMARY KEY,
	message TEXT NOT NULL,
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	sender_id VARCHAR(64) NULL,
	sender_color VARCHAR(7) NULL,
	bubble_position ENUM('left', 'right') NULL,
	reply_to_id INT NULL
)
");

/* CREATE USERS TABLE */
$pdo->exec("
CREATE TABLE IF NOT EXISTS chat_users (
	sender_id VARCHAR(64) PRIMARY KEY,
	last_seen TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	last_typed DATETIME NULL,
	has_draft TINYINT(1) NOT NULL DEFAULT 0
)
");



/* =================================
   UPDATE TYPING STATUS
================================= */

if (
	$_SERVER['REQUEST_METHOD'] === 'POST' &&
	($_POST['action'] ?? '') === 'typing'
) {

	header('Content-Type: application/json');

	$senderId = $_COOKIE['chat_id'] ?? '';

	if (!preg_match('/^[a-f0-9]{32}$/', $senderId)) {
		echo json_encode(['success' => false]);
		exit;
	}

	$hasDraft = ($_POST['has_draft'] ?? '') === '1';
	$active = ($_POST['active'] ?? '') === '1';

	$stmt = $pdo->prepare("
		INSERT INTO chat_users
			(sender_id, last_seen, last_typed, has_draft)

		VALUES (
			?,
			NOW(),
			IF(?, NOW(), NULL),
			?
		)

		ON DUPLICATE KEY UPDATE

			has_draft = VALUES(has_draft),

			last_typed = IF(
				?,
				NOW(),
				last_typed
			)
	");

	$stmt->execute([
		$senderId,
		(int)$active,
		(int)$hasDraft,
		(int)$active
	]);

	echo json_encode(['success' => true]);
	exit;
}


/* =================================
   FETCH TYPING STATUS
================================= */

if (($_GET['action'] ?? '') === 'typing') {

	header('Content-Type: application/json');

	$senderId = $_COOKIE['chat_id'] ?? '';

	if (!preg_match('/^[a-f0-9]{32}$/', $senderId)) {
		echo json_encode(['success' => false]);
		exit;
	}

	$stmt = $pdo->prepare("
		SELECT
			COUNT(*) AS typing_count,

			MAX(
				last_typed >= NOW() - INTERVAL 2 SECOND
			) AS actively_typing

		FROM chat_users

		WHERE sender_id != ?
		AND has_draft = 1
	");

	$stmt->execute([$senderId]);

	$result = $stmt->fetch(PDO::FETCH_ASSOC);

	echo json_encode([
		'success' => true,
		'count' => (int)$result['typing_count'],
		'active' => (bool)$result['actively_typing']
	]);

	exit;
}

/* SEND MESSAGE */
if ($_SERVER['REQUEST_METHOD'] === 'POST'
	&& ($_POST['action'] ?? '') === 'send') {

	header('Content-Type: application/json');

	$message = trim($_POST['message'] ?? '');

	$replyToId = null;

	if (isset($_POST['reply_to_id']) && $_POST['reply_to_id'] !== '') {

		$requestedReplyId = filter_var(
			$_POST['reply_to_id'],
			FILTER_VALIDATE_INT
		);

		if ($requestedReplyId === false || $requestedReplyId <= 0) {
			echo json_encode(['success' => false]);
			exit;
		}

		// Make sure the original message actually exists
		$checkReply = $pdo->prepare("
			SELECT id FROM chat_messages WHERE id = ?
		");

		$checkReply->execute([$requestedReplyId]);

		if (!$checkReply->fetchColumn()) {
			echo json_encode(['success' => false]);
			exit;
		}

		$replyToId = $requestedReplyId;
	}

	// Read identity from browser cookies
	$senderId = $_COOKIE['chat_id'] ?? '';
	$senderColor = $_COOKIE['chat_color'] ?? '';
	$bubblePosition = $_COOKIE['chat_position'] ?? '';

	if (
		$message === '' ||
		mb_strlen($message) > 5000 ||
		!preg_match('/^[a-f0-9]{32}$/', $senderId) ||
		!preg_match('/^#[a-fA-F0-9]{6}$/', $senderColor) ||
		!preg_match('/^(right|left)$/', $bubblePosition)
	) {
		echo json_encode(['success' => false]);
		exit;
	}

	$stmt = $pdo->prepare("
		INSERT INTO chat_messages
		(message, sender_id, sender_color, bubble_position, reply_to_id)
		VALUES (?, ?, ?, ?, ?)
	");

	$stmt->execute([
		$message,
		$senderId,
		$senderColor,
		$bubblePosition,
		$replyToId
	]);

	echo json_encode(['success' => true]);
	exit;
}


/* FETCH NEW MESSAGES */
if (($_GET['action'] ?? '') === 'fetch') {

	header('Content-Type: application/json');

	$after = max(0, (int)($_GET['after'] ?? 0));

	$stmt = $pdo->prepare("
		SELECT
			m.id,
			m.message,
			m.created_at,
			m.sender_id,
			m.sender_color,
			m.bubble_position,
			m.reply_to_id,

			r.message AS reply_message,
			r.sender_id AS reply_sender_id

		FROM chat_messages m

		LEFT JOIN chat_messages r
			ON m.reply_to_id = r.id

		WHERE m.id > ?

		ORDER BY m.id ASC

		LIMIT 500
	");

	$stmt->execute([$after]);

	echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
	exit;
}


/* ONLINE USERS */

if (($_POST['action'] ?? '') === 'online') {

	header('Content-Type: application/json');

	$senderId = $_COOKIE['chat_id'] ?? '';

	if (!preg_match('/^[a-f0-9]{32}$/', $senderId)) {
		echo json_encode(['success' => false]);
		exit;
	}

	// Update this visitor's last activity
	$stmt = $pdo->prepare("
		INSERT INTO chat_users (sender_id, last_seen)
		VALUES (?, NOW())
		ON DUPLICATE KEY UPDATE last_seen = NOW()
	");

	$stmt->execute([$senderId]);

	// Count visitors active within the last few seconds
	$count = $pdo->query("
		SELECT COUNT(*)
		FROM chat_users
		WHERE last_seen >= NOW() - INTERVAL 3 SECOND
	")->fetchColumn();

	echo json_encode([
		'success' => true,
		'online' => (int)$count
	]);

	exit;
}


/* LOAD LATEST MESSAGES ONLY */
$initialMessages = $pdo->query("
	SELECT
		m.id,
		m.message,
		m.created_at,
		m.sender_id,
		m.sender_color,
		m.bubble_position,
		m.reply_to_id,

		r.message AS reply_message,
		r.sender_id AS reply_sender_id

	FROM (
		SELECT *
		FROM chat_messages
		ORDER BY id DESC
		LIMIT 4600
	) AS m

	LEFT JOIN chat_messages r
		ON m.reply_to_id = r.id

	ORDER BY m.id ASC

")->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($page_title) ?></title>

<style>
* {
	box-sizing: border-box;
}

body {
	margin: 0;
	font-family: Arial, sans-serif;
	background: #e9edf3;
}

.chat-container {
	max-width: 750px;
	height: 95vh;
	margin: 2.5vh auto;
	background: white;
	display: flex;
	flex-direction: column;
	border-radius: 12px;
	overflow: hidden;
	box-shadow: 0 4px 20px #0001;
	position: relative;
}

.header {
	background: #2563eb;
	color: white;
	padding: 15px 20px;
}

.header-content {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 15px;
}

.header-text h3 {
	margin: 0;
}

.status {
	font-size: 12px;
	margin-top: 6px;
	opacity: .8;
}

.online-count {
	display: flex;
	align-items: center;
	gap: 6px;
	margin-top: 7px;
	font-size: 11px;
	opacity: .9;
}

.online-indicator {
	width: 7px;
	height: 7px;
	background: #4ade80;
	border-radius: 50%;
	box-shadow: 0 0 5px #4ade80;
}

.color-picker-area {
	display: flex;
	align-items: center;
}

.color-picker-area input {
	width: 38px;
	height: 30px;
	padding: 0;
	border: 2px solid white;
	background: transparent;
	cursor: pointer;
}

.messages {
	flex: 1;
	overflow-y: auto;
	padding: 20px;
	background: #f7f8fa;
	overscroll-behavior: contain;
	overflow-anchor: none;
}

.message {
	width: fit-content;
	max-width: 85%;
	padding: 12px 16px;
	margin-bottom: 12px;
	border-radius: 12px;
	overflow-wrap: anywhere;
	box-shadow: 0 1px 3px #0001;
	color: #222;
	line-height: 1.4;
}

.message.left {
	margin-right: auto;
	border-bottom-left-radius: 4px;
}

.message.right {
	margin-left: auto;
	border-bottom-right-radius: 4px;
}

.message small {
	display: block;
	font-size: 10px;
	color: #555;
	margin-top: 7px;
	opacity: .7;
}

.input-area {
	display: flex;
	padding: 15px;
	gap: 10px;
	border-top: 1px solid #ddd;
	background: white;
}

.input-area input {
	flex: 1;
	min-width: 0;
	padding: 14px;
	border: 1px solid #ddd;
	border-radius: 25px;
	font-size: 15px;
	outline: none;
}

.input-area input:focus {
	border-color: #2563eb;
}

.input-area button {
	padding: 0 25px;
	border-radius: 25px;
	background: #2563eb;
	color: white;
	border: none;
	font-size: 15px;
	cursor: pointer;
}

.input-area button:disabled {
	opacity: .5;
	cursor: wait;
}


/* =================================
   NEW MESSAGE SCROLL BUTTON
================================= */

.new-messages-button {
	position: absolute;
	right: 22px;
	bottom: 95px;

	width: 46px;
	height: 46px;

	display: flex;
	align-items: center;
	justify-content: center;

	border: none;
	border-radius: 50%;

	background: #2563eb;
	color: white;

	box-shadow: 0 3px 12px #0003;

	cursor: pointer;
	z-index: 10;

	transition:
		opacity .2s ease,
		transform .2s ease,
		background .2s ease;
}

.new-messages-button:hover {
	background: #1d4ed8;
	transform: translateY(-2px);
}

.new-messages-button:active {
	transform: scale(.92);
}

.new-messages-button svg {
	width: 23px;
	height: 23px;
	stroke: currentColor;
	stroke-width: 2.5;
	fill: none;
	stroke-linecap: round;
	stroke-linejoin: round;
}

/* Unread notification circle */

.unread-count {
	position: absolute;
	top: -15px;
	right: -8px;

	min-width: 30px;
	height: 30px;

	padding: 0 5px;

	display: flex;
	align-items: center;
	justify-content: center;

	border-radius: 20px;

	background: #ef4444;
	color: white;

	border: 2px solid white;

	font-size: 15px;
	font-weight: bold;
	line-height: 1;
}

/* Hide button when there are no unread messages */
[hidden],
.unread-count[hidden],
.new-messages-button[hidden] {
	display: none !important;
}

/* =================================
REPLY PREVIEW INSIDE MESSAGES
================================= */

.message-reply {
	padding: 8px 11px;
	margin-bottom: 9px;

	border-left: 4px solid #2563eb;
	border-radius: 5px;

	background: #0000000d;

	cursor: pointer;
	overflow: hidden;
}

.message-reply strong {
	display: block;
	font-size: 11px;
	margin-bottom: 3px;
	color: #2563eb;
}

.message-reply span {
	display: block;
	font-size: 12px;
	opacity: .8;

	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

/* =================================
REPLY COMPOSER
================================= */

.input-area {
	flex-direction: column;
}

.composer-row {
	display: flex;
	gap: 10px;
	width: 100%;
}

.reply-preview {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 10px;

	padding: 9px 12px;

	background: #f0f4ff;
	border-left: 4px solid #2563eb;
	border-radius: 7px;
	width: calc(100% - 60px);
}

.reply-preview-content {
	min-width: 0;
	flex: 1;
}

.reply-preview-content strong {
	display: block;
	font-size: 12px;
	color: #2563eb;
	margin-bottom: 3px;
}

.reply-preview-content span {
	display: block;
	font-size: 12px;
	color: #555;

	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

button.cancel-reply {
	border: none;
	background: transparent;
	color: #666;
	font-size: 21px;
	cursor: pointer;
	padding: 4px 8px;
}

/* =================================
MESSAGE CONTEXT MENU
================================= */

.message-context-menu {
	position: fixed;
	z-index: 1000;

	min-width: 155px;

	padding: 5px;

	background: white;
	border-radius: 9px;

	box-shadow: 0 4px 20px #0003;
	border: 1px solid #ddd;
}

.message-context-menu button {
	display: flex;
	align-items: center;
	gap: 10px;

	width: 100%;

	padding: 11px 12px;

	border: none;
	border-radius: 5px;

	background: white;
	color: #222;

	font-size: 14px;
	text-align: left;

	cursor: pointer;
}

.message-context-menu button:hover {
	background: #f0f3f8;
}

.message.dragging {
	transition: none;
	opacity: .8;
	box-shadow: 0 3px 12px #0003;
}

.message.reply-highlight {
	outline: 2px solid #2563eb;
	outline-offset: 3px;
}

/* =================================
   THREE DOT TYPING BUBBLE
================================= */

.typing-bubble {

	width: fit-content;

	display: flex;
	align-items: center;
	gap: 5px;

	padding: 13px 17px;
	margin-bottom: 12px;

	background: #e5e7eb;

	border-radius: 12px;
	border-bottom-left-radius: 4px;

	box-shadow: 0 1px 3px #0001;

}

.typing-bubble[hidden] {
	display: none !important;
}

.typing-bubble span {

	width: 7px;
	height: 7px;

	background: #777;

	border-radius: 50%;

	/* Stationary by default */

	transform: translateY(0);
	opacity: .65;

}

/* Only animate when actively typing */

.typing-bubble.is-animating span {

	animation: typingBounce 1.2s infinite ease-in-out;

}

.typing-bubble.is-animating span:nth-child(1) {
	animation-delay: 0s;
}

.typing-bubble.is-animating span:nth-child(2) {
	animation-delay: .15s;
}

.typing-bubble.is-animating span:nth-child(3) {
	animation-delay: .3s;
}

@keyframes typingBounce {

	0%, 60%, 100% {
		transform: translateY(0);
		opacity: .45;
	}

	30% {
		transform: translateY(-5px);
		opacity: 1;
	}

}

@media(max-width: 600px) {

	.chat-container {
		height: 100dvh;
		margin: 0;
		border-radius: 0;
	}

	.new-messages-button {
		right: 18px;
		bottom: 82px;
	}

}
</style>
</head>

<body>

<div class="chat-container">

	<div class="header">
		<div class="header-content">
			<div class="header-text">
				<h3><?= htmlspecialchars($page_header) ?></h3>
				<div class="status"><?= htmlspecialchars($page_status) ?></div>
				<div class="online-count">
					<span class="online-indicator"></span>
					<span id="onlineCount">online</span>
				</div>
			</div>

			<div class="color-picker-area">
				<input type="color" id="bubbleColor" title="Change bubble color">
			</div>
		</div>
	</div>

	<div class="messages" id="messages">

		<?php foreach ($initialMessages as $msg): ?>

			<div class="message"
				data-id="<?= (int)$msg['id'] ?>"
				data-sender-id="<?= htmlspecialchars($msg['sender_id'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
				data-color="<?= htmlspecialchars($msg['sender_color'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
				data-position="<?= htmlspecialchars($msg['bubble_position'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
				data-reply-to="<?= (int)($msg['reply_to_id'] ?? 0) ?>"
			>

				<?php if (!empty($msg['reply_to_id']) && $msg['reply_message'] !== null): ?>

					<div class="message-reply"
						data-reply-target="<?= (int)$msg['reply_to_id'] ?>">

						<strong>Replying to <?=
							($msg['reply_sender_id'] ?? '') === ($_COOKIE['chat_id'] ?? '')
							? 'yourself'
							: 'message'
						?></strong>

						<span><?= htmlspecialchars($msg['reply_message'], ENT_QUOTES, 'UTF-8') ?></span>

					</div>

				<?php endif; ?>

				<div class="message-body"><?= nl2br(htmlspecialchars($msg['message'], ENT_QUOTES, 'UTF-8')) ?></div>

				<small><?= htmlspecialchars($msg['created_at']) ?></small>
			</div>

		<?php endforeach; ?>

		<!-- TYPING BUBBLE -->

		<div class="typing-bubble" id="typingBubble" hidden>

			<span></span>
			<span></span>
			<span></span>

		</div>

	</div>

	<!-- NEW MESSAGE BUTTON -->

	<button
		type="button"
		class="new-messages-button"
		id="newMessagesButton"
		aria-label="Scroll to latest messages"
		title="Scroll to latest messages"
		hidden
	>

		<svg viewBox="0 0 24 24" aria-hidden="true">
			<path d="M12 5v14"></path>
			<path d="m19 12-7 7-7-7"></path>
		</svg>

		<span class="unread-count" id="unreadCount">0</span>

	</button>

	<form class="input-area" id="chatForm">

		<!-- REPLY PREVIEW -->

		<div class="reply-preview" id="replyPreview" hidden>

			<div class="reply-preview-content">
				<strong id="replyPreviewTitle">Replying to message</strong>
				<span id="replyPreviewText"></span>
			</div>

			<button
				type="button"
				class="cancel-reply"
				id="cancelReply"
				aria-label="Cancel reply"
			>&times;</button>

		</div>

		<!-- MESSAGE INPUT -->

		<div class="composer-row">

			<input
				type="text"
				id="message"
				placeholder="Write a message..."
				autocomplete="off"
				maxlength="5000"
				required
			>

			<button type="submit" id="sendButton">Send</button>

		</div>

	</form>

</div>

<div class="message-context-menu" id="messageContextMenu" hidden>

	<button type="button" id="contextReply">
		<span>↩</span> Reply
	</button>

	<button type="button" id="contextCopy">
		<span>▢</span> Copy
	</button>

</div>

<script>
const messages = document.getElementById('messages');
const messageInput = document.getElementById('message');
const chatForm = document.getElementById('chatForm');
const sendButton = document.getElementById('sendButton');
const bubbleColorPicker = document.getElementById('bubbleColor');
const newMessagesButton = document.getElementById('newMessagesButton');
const unreadCountDisplay = document.getElementById('unreadCount');
const replyPreview = document.getElementById('replyPreview');
const replyPreviewTitle = document.getElementById('replyPreviewTitle');
const replyPreviewText = document.getElementById('replyPreviewText');
const cancelReply = document.getElementById('cancelReply');
const contextMenu = document.getElementById('messageContextMenu');
const contextReply = document.getElementById('contextReply');
const contextCopy = document.getElementById('contextCopy');
const typingBubble = document.getElementById('typingBubble');

let typingTimer = null;
let typingActive = false;
let lastTypingSent = 0;

let replyToId = null;
let selectedMessage = null;

/* COOKIE HELPERS */

function getCookie(name) {
	const match = document.cookie.match(
		new RegExp('(?:^|; )' + name + '=([^;]*)')
	);

	return match ? decodeURIComponent(match[1]) : null;
}

function setCookie(name, value) {
	let cookie = name + '=' + encodeURIComponent(value) +
		'; max-age=315360000; path=/; SameSite=Lax';

	if (location.protocol === 'https:') {
		cookie += '; Secure';
	}

	document.cookie = cookie;
}


/* GENERATE RANDOM BROWSER ID */

let senderId = getCookie('chat_id');

if (!senderId || !/^[a-f0-9]{32}$/.test(senderId)) {
	senderId = crypto.randomUUID().replace(/-/g, '');
	setCookie('chat_id', senderId);
}

/* SET RIGHT BUBBLE POSITION */

let bubblePosition = getCookie('chat_position');

if (!bubblePosition || !/^(left|right)$/.test(bubblePosition)) {
	bubblePosition = 'right';
	setCookie('chat_position', bubblePosition);
}


/* GENERATE RANDOM PASTEL COLOR */

function randomColor() {
	const channel = () => Math.floor(160 + Math.random() * 96)
		.toString(16)
		.padStart(2, '0');

	return '#' + channel() + channel() + channel();
}

let senderColor = getCookie('chat_color');

if (!senderColor || !/^#[a-fA-F0-9]{6}$/.test(senderColor)) {
	senderColor = randomColor();
	setCookie('chat_color', senderColor);
}

/* BUBBLE COLOR PICKER */

bubbleColorPicker.value = senderColor;

bubbleColorPicker.addEventListener('input', function() {

	senderColor = this.value;

	// Save selected color
	setCookie('chat_color', senderColor);

	// Update existing messages belonging to this browser
	// document.querySelectorAll('.message').forEach(div => {
	//     if (div.dataset.senderId === senderId) {
	//         div.style.backgroundColor = senderColor;
	//     }
	// });

});


/* CHAT IDENTITY */

let lastId = <?= !empty($initialMessages)
	? (int)end($initialMessages)['id']
	: 0 ?>;

let loading = false;


/* =================================
   SMART SCROLLING SYSTEM
================================= */

/*
   Distance from the bottom that still
   counts as being at the bottom.
*/

const BOTTOM_THRESHOLD = 32;

/*
   Number of new messages received
   while the user is away from bottom.
*/

let unreadMessages = 0;


/* CHECK WHETHER USER IS AT BOTTOM */

function isAtBottom() {

	return (
		messages.scrollHeight -
		messages.scrollTop -
		messages.clientHeight
	) <= BOTTOM_THRESHOLD;

}


/* SCROLL TO BOTTOM */

function scrollToBottom() {

	messages.scrollTop = messages.scrollHeight;

}


/* UPDATE UNREAD MESSAGE INDICATOR */

function updateUnreadIndicator() {

	// Show arrow whenever not at bottom
	newMessagesButton.hidden = isAtBottom();

	// Update notification number
	unreadCountDisplay.textContent = unreadMessages;

	// Hide red circle when count is zero
	unreadCountDisplay.hidden = unreadMessages === 0;

}


/* CLEAR UNREAD MESSAGES */

function clearUnreadMessages() {

	unreadMessages = 0;

	updateUnreadIndicator();

}


/* SCROLL BUTTON CLICK */

newMessagesButton.addEventListener('click', function() {

	scrollToBottom();

	clearUnreadMessages();

});


/* DETECT MANUAL SCROLLING */

messages.addEventListener('scroll', function() {

	if (isAtBottom()) {

		clearUnreadMessages();

	} else {

		updateUnreadIndicator();

	}

});


/* =================================
   STYLE EXISTING MESSAGES
================================= */

function styleMessage(div, senderId, color, position) {

	div.classList.remove('left', 'right');

	div.classList.add(
		position == 'left' ? 'left' : 'right'
	);

	if (/^#[a-fA-F0-9]{6}$/.test(color || '')) {
		div.style.backgroundColor = color;
	}
}

const existingMessages = messages.querySelectorAll('.message');

existingMessages.forEach(div => {
	if (div.dataset.senderId) {
		styleMessage(
			div,
			div.dataset.senderId,
			div.dataset.color,
			div.dataset.position
		);
	} else {
		// Older messages created before cookie identities
		div.classList.add('left');
	}
});


/* ESCAPE HTML */

function escapeHTML(str) {
	return String(str).replace(/[&<>"']/g, char => ({
		'&': '&amp;',
		'<': '&lt;',
		'>': '&gt;',
		'"': '&quot;',
		"'": '&#039;'
	})[char]);
}

/* =================================
   REPLY SYSTEM
================================= */


/* GET MESSAGE TEXT */

function getMessageText(div) {

	return div.querySelector('.message-body').textContent;

}


/* START REPLY */

function startReply(div) {

	if (!div) return;

	replyToId = div.dataset.id;

	const messageText = getMessageText(div);

	const originalSender = div.dataset.senderId;

	const senderName =
		originalSender === senderId ? 'yourself' : 'message';

	replyPreviewTitle.textContent =
		'Replying to ' + senderName;

	replyPreviewText.textContent = messageText;

	replyPreview.hidden = false;

	messageInput.focus();

	closeContextMenu();

}


/* CANCEL REPLY */

function cancelReplyMode() {

	replyToId = null;

	replyPreview.hidden = true;

	replyPreviewText.textContent = '';

}

cancelReply.addEventListener('click', cancelReplyMode);


/* CLOSE CONTEXT MENU */

function closeContextMenu() {

	contextMenu.hidden = true;

	selectedMessage = null;

}


/* OPEN CONTEXT MENU */

function openContextMenu(div, x, y) {

	selectedMessage = div;

	contextMenu.hidden = false;

	const menuWidth = 170;
	const menuHeight = 110;

	contextMenu.style.left =
		Math.min(x, window.innerWidth - menuWidth - 10) + 'px';

	contextMenu.style.top =
		Math.min(y, window.innerHeight - menuHeight - 10) + 'px';

}


/* RIGHT CLICK */

messages.addEventListener('contextmenu', function(e) {

	const div = e.target.closest('.message');

	if (!div) return;

	e.preventDefault();

	openContextMenu(div, e.clientX, e.clientY);

});


/* REPLY FROM CONTEXT MENU */

contextReply.addEventListener('click', function() {

	if (selectedMessage) {

		const div = selectedMessage;

		startReply(div);

	}

});


/* COPY FROM CONTEXT MENU */

contextCopy.addEventListener('click', async function() {

	if (!selectedMessage) return;

	const text = getMessageText(selectedMessage);

	try {

		await navigator.clipboard.writeText(text);

	} catch (error) {

		// Fallback for browsers without clipboard permission
		const textarea = document.createElement('textarea');

		textarea.value = text;

		document.body.appendChild(textarea);

		textarea.select();

		document.execCommand('copy');

		textarea.remove();

	}

	closeContextMenu();

});


/* CLOSE MENU WHEN CLICKING ELSEWHERE */

document.addEventListener('click', function(e) {

	if (
		!contextMenu.contains(e.target) &&
		!e.target.closest('.message')
	) {

		closeContextMenu();

	}

});


/* CLOSE MENU ON ESCAPE */

document.addEventListener('keydown', function(e) {

	if (e.key === 'Escape') {

		closeContextMenu();

		cancelReplyMode();

	}

});


/* =================================
   DRAG MESSAGE TO REPLY
================================= */

let dragMessage = null;
let dragStartX = 0;
let dragStartY = 0;
let dragCurrentX = 0;
let dragCurrentY = 0;

let longPressTimer = null;
let longPressTriggered = false;

const DRAG_THRESHOLD = 65;
const LONG_PRESS_DURATION = 550;


/* POINTER DOWN */

messages.addEventListener('pointerdown', function(e) {

	const div = e.target.closest('.message');

	if (!div || e.button !== 0) return;

	dragMessage = div;

	dragStartX = e.clientX;
	dragStartY = e.clientY;

	dragCurrentX = e.clientX;
	dragCurrentY = e.clientY;

	longPressTriggered = false;

	/*
	   Long press for touch devices.
	*/

	if (e.pointerType === 'touch') {

		longPressTimer = setTimeout(function() {

			if (dragMessage) {

				longPressTriggered = true;

				openContextMenu(
					dragMessage,
					dragCurrentX,
					dragCurrentY
				);

			}

		}, LONG_PRESS_DURATION);

	}

});


/* POINTER MOVE */

document.addEventListener('pointermove', function(e) {

	if (!dragMessage) return;

	dragCurrentX = e.clientX;
	dragCurrentY = e.clientY;

	const dx = e.clientX - dragStartX;
	const dy = e.clientY - dragStartY;

	/*
	   Cancel long press if the user moves.
	*/

	if (Math.abs(dx) > 10 || Math.abs(dy) > 10) {

		clearTimeout(longPressTimer);

	}

	/*
	   Only activate reply dragging when moving
	   horizontally to the RIGHT.

	   Vertical movement remains normal scrolling.
	*/

	if (
		dx > 0 &&
		dx > Math.abs(dy) * 1.3
	) {

		if (dx > 10) {

			dragMessage.classList.add('dragging');

			dragMessage.style.transform =
				'translateX(' + Math.min(dx, 100) + 'px)';

		}

	}

});


/* POINTER UP */

document.addEventListener('pointerup', function(e) {

	if (!dragMessage) return;

	clearTimeout(longPressTimer);

	const dx = e.clientX - dragStartX;
	const dy = e.clientY - dragStartY;

	const div = dragMessage;

	div.classList.remove('dragging');

	div.style.transform = '';

	/*
	   Only reply if the message was dragged
	   sufficiently far to the right.
	*/

	if (
		!longPressTriggered &&
		dx >= DRAG_THRESHOLD &&
		dx > Math.abs(dy) * 1.3
	) {

		startReply(div);

	}

	dragMessage = null;

});


/* CANCEL POINTER */

document.addEventListener('pointercancel', function() {

	clearTimeout(longPressTimer);

	if (dragMessage) {

		dragMessage.classList.remove('dragging');

		dragMessage.style.transform = '';

	}

	dragMessage = null;

});

/* JUMP TO ORIGINAL REPLIED MESSAGE */

messages.addEventListener('click', function(e) {

	const reply = e.target.closest('.message-reply');

	if (!reply) return;

	const targetId = reply.dataset.replyTarget;

	const originalMessage = messages.querySelector(
		'.message[data-id="' + targetId + '"]'
	);

	if (!originalMessage) return;

	originalMessage.scrollIntoView({
		behavior: 'smooth',
		block: 'center'
	});

	originalMessage.classList.add('reply-highlight');

	setTimeout(() => {

		originalMessage.classList.remove('reply-highlight');

	}, 1500);

});


/* =================================
   LOAD NEW MESSAGES
================================= */

async function loadMessages() {

	if (loading) return;

	loading = true;

	try {

		const response = await fetch(
			'?action=fetch&after=' + lastId
		);

		if (!response.ok) return;

		const data = await response.json();

		if (data.length) {

			/*
			   IMPORTANT:

			   Check the scroll position BEFORE
			   adding the new messages.
			*/

			const wasAtBottom = isAtBottom();

			/*
			   Remember the current position
			   so we can preserve it if needed.
			*/

			const previousScrollTop = messages.scrollTop;


			/* APPEND NEW MESSAGES */

			data.forEach(msg => {

				const div = document.createElement('div');

					div.className = 'message';

					div.dataset.id = msg.id;
					div.dataset.senderId = msg.sender_id || '';
					div.dataset.color = msg.sender_color || '';
					div.dataset.position = msg.bubble_position || '';
					div.dataset.replyTo = msg.reply_to_id || '0';


					/* REPLY PREVIEW */

					if (msg.reply_to_id && msg.reply_message !== null) {

						const reply = document.createElement('div');

						reply.className = 'message-reply';

						reply.dataset.replyTarget = msg.reply_to_id;

						const replyTitle = document.createElement('strong');

						replyTitle.textContent =
							msg.reply_sender_id === senderId
							? 'Replying to yourself'
							: 'Replying to message';

						const replyText = document.createElement('span');

						replyText.textContent = msg.reply_message;

						reply.appendChild(replyTitle);
						reply.appendChild(replyText);

						div.appendChild(reply);

					}


					/* MESSAGE BODY */

					const body = document.createElement('div');

					body.className = 'message-body';

					body.textContent = msg.message;

					div.appendChild(body);


					/* TIMESTAMP */

					const timestamp = document.createElement('small');

					timestamp.textContent = msg.created_at;

					div.appendChild(timestamp);


					/* MESSAGE STYLING */

					if (msg.sender_id) {

						styleMessage(
							div,
							msg.sender_id,
							msg.sender_color,
							msg.bubble_position
						);

					}

					messages.insertBefore(div, typingBubble);

					lastId = Math.max(lastId, Number(msg.id));

			});


			/*
			   SMART SCROLLING:

			   If already at bottom:
			   Automatically follow the newest message.

			   If NOT at bottom:
			   Preserve the current scroll position
			   and increment the unread counter.
			*/

			if (wasAtBottom) {

				scrollToBottom();

				clearUnreadMessages();

			} else {

				// Preserve current scroll position
				messages.scrollTop = previousScrollTop;

				// Only count messages sent by OTHER people
				const incomingMessages = data.filter(msg =>
					msg.sender_id !== senderId
				);

				unreadMessages += incomingMessages.length;

				// Keep button visible even when unread count is zero
				updateUnreadIndicator();

			}

		}

	} catch (error) {
		console.error('Chat update failed', error);

	} finally {
		loading = false;
	}
}


/* =================================
   TYPING NOTIFICATIONS
================================= */

/* SEND TYPING STATUS */

async function sendTypingStatus(hasDraft, active) {

	try {

		const formData = new FormData();

		formData.append('action', 'typing');
		formData.append('has_draft', hasDraft ? '1' : '0');
		formData.append('active', active ? '1' : '0');

		await fetch('', {
			method: 'POST',
			body: formData
		});

	} catch (error) {

		console.error('Typing status failed:', error);

	}

}


/* USER STARTS TYPING */

function startTyping() {

	typingActive = true;

	if (Date.now() - lastTypingSent >= 1000) {

		lastTypingSent = Date.now();

		sendTypingStatus(true, true);

	}

	clearTimeout(typingTimer);

	typingTimer = setTimeout(function() {

		stopTyping();

	}, 1500);

}


/* USER STOPS TYPING */

function stopTyping() {

	clearTimeout(typingTimer);

	if (!typingActive) return;

	typingActive = false;

	// Keep the draft, but stop the animation
	sendTypingStatus(true, false);

}


/* INPUT CHANGES */

messageInput.addEventListener('input', function() {

	const hasDraft = this.value.trim() !== '';

	if (!hasDraft) {

		clearTimeout(typingTimer);

		typingActive = false;

		lastTypingSent = 0;

		// Remove typing bubble completely for other users
		sendTypingStatus(false, false);

		return;

	}

	startTyping();

});


/* STOP ACTIVE TYPING WHEN INPUT LOSES FOCUS */

messageInput.addEventListener('blur', function() {

	stopTyping();

});


async function updateTypingIndicator() {

	try {

		const response = await fetch('?action=typing');

		if (!response.ok) return;

		const data = await response.json();

		if (!data.success) return;

		const shouldShow = data.count > 0;
		const wasAtBottom = isAtBottom();
		const previousScrollTop = messages.scrollTop;

		// Show bubble if anyone has an unsent draft
		typingBubble.hidden = !shouldShow;

		// Animate only if someone is actively typing
		typingBubble.classList.toggle(
			'is-animating',
			data.active
		);

		if (shouldShow && wasAtBottom) {

			scrollToBottom();

		} else {

			messages.scrollTop = previousScrollTop;

		}

	} catch (error) {

		console.error('Typing indicator update failed:', error);

	}

}

/* CHECK EVERY SECOND */

updateTypingIndicator();

setInterval(updateTypingIndicator, 1000);


/* SEND MESSAGE */

chatForm.addEventListener('submit', async function(e) {

	e.preventDefault();

	const text = messageInput.value.trim();

	if (!text) return;

	// CHAT COMMANDS
	if (text.toLowerCase() === '/left') {

		bubblePosition = 'left';
		setCookie('chat_position', 'left');

		messageInput.value = '';
		messageInput.focus();

		return;
	}

	if (text.toLowerCase() === '/right') {

		bubblePosition = 'right';
		setCookie('chat_position', 'right');

		messageInput.value = '';
		messageInput.focus();

		return;
	}

	sendButton.disabled = true;

	const formData = new FormData();

	formData.append('action', 'send');
	formData.append('message', text);

	if (replyToId !== null) {
		formData.append('reply_to_id', replyToId);
	}

	try {

		const response = await fetch('', {
			method: 'POST',
			body: formData
		});

		const result = await response.json();

		if (result.success) {

			messageInput.value = '';
			// Notify other users that the input is now empty
			messageInput.dispatchEvent(new Event('input'));
			cancelReplyMode();
			await loadMessages();

		} else {
			alert('Unable to send message.');
		}

	} catch (error) {
		alert('Unable to send message.');

	} finally {
		sendButton.disabled = false;
		messageInput.focus();
	}
});


/* CHECK FOR NEW MESSAGES EVERY SECOND */

setInterval(loadMessages, 1000);


/* SCROLL TO LATEST MESSAGE ON OPEN */

scrollToBottom();


/* ONLINE USERS COUNTER */

async function updateOnlineCount() {

	try {

		const formData = new FormData();
		formData.append('action', 'online');

		const response = await fetch('', {
			method: 'POST',
			body: formData
		});

		if (!response.ok) return;

		const data = await response.json();

		if (data.success) {
			document.getElementById('onlineCount').textContent =
				data.online + ' online';
		}

	} catch (error) {
		console.error('Online counter failed:', error);
	}
}

// Update immediately
updateOnlineCount();

// Update every 2 seconds
setInterval(updateOnlineCount, 2000);

</script>

</body>
</html>
