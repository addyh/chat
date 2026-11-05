
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
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)
");

/* ADD SENDER ID AND COLOR AND POSITION TO EXISTING TABLE */
try {
    $pdo->exec("
        ALTER TABLE chat_messages
        ADD COLUMN sender_id VARCHAR(64) NULL
    ");
} catch (PDOException $e) {}

try {
    $pdo->exec("
        ALTER TABLE chat_messages
        ADD COLUMN sender_color VARCHAR(7) NULL
    ");
} catch (PDOException $e) {}

try {
    $pdo->exec("
        ALTER TABLE chat_messages
        ADD COLUMN bubble_position ENUM('left', 'right') NULL
    ");
} catch (PDOException $e) {}

/* SEND MESSAGE */
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'send') {

    header('Content-Type: application/json');

    $message = trim($_POST['message'] ?? '');

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
        (message, sender_id, sender_color, bubble_position)
        VALUES (?, ?, ?, ?)
    ");

    $stmt->execute([
        $message,
        $senderId,
        $senderColor,
        $bubblePosition
    ]);

    echo json_encode(['success' => true]);
    exit;
}


/* FETCH NEW MESSAGES */
if (($_GET['action'] ?? '') === 'fetch') {

    header('Content-Type: application/json');

    $after = max(0, (int)($_GET['after'] ?? 0));

    $stmt = $pdo->prepare("
        SELECT id, message, created_at, sender_id, sender_color, bubble_position
        FROM chat_messages
        WHERE id > ?
        ORDER BY id ASC
        LIMIT 500
    ");

    $stmt->execute([$after]);

    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}


/* LOAD ALL PREVIOUS MESSAGES */
$initialMessages = $pdo->query("
    SELECT id, message, created_at, sender_id, sender_color, bubble_position
    FROM chat_messages
    ORDER BY id ASC
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

@media(max-width: 600px) {
    .chat-container {
        height: 100dvh;
        margin: 0;
        border-radius: 0;
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
            </div>

            <div class="color-picker-area">
                <input type="color" id="bubbleColor" title="Change bubble color">
            </div>
        </div>
    </div>

    <div class="messages" id="messages">

        <?php foreach ($initialMessages as $msg): ?>

            <div
                class="message"
                data-id="<?= (int)$msg['id'] ?>"
                data-sender-id="<?= htmlspecialchars($msg['sender_id'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                data-color="<?= htmlspecialchars($msg['sender_color'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                data-position="<?= htmlspecialchars($msg['bubble_position'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
            >
                <div><?= nl2br(htmlspecialchars($msg['message'], ENT_QUOTES, 'UTF-8')) ?></div>
                <small><?= htmlspecialchars($msg['created_at']) ?></small>
            </div>

        <?php endforeach; ?>

    </div>

    <form class="input-area" id="chatForm">
        <input
            type="text"
            id="message"
            placeholder="Write a message..."
            autocomplete="off"
            maxlength="5000"
            required
        >
        <button type="submit" id="sendButton">Send</button>
    </form>

</div>

<script>
const messages = document.getElementById('messages');
const messageInput = document.getElementById('message');
const chatForm = document.getElementById('chatForm');
const sendButton = document.getElementById('sendButton');
const bubbleColorPicker = document.getElementById('bubbleColor');

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




/* STYLE EXISTING MESSAGES */

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


/* LOAD NEW MESSAGES */

async function loadMessages() {

    if (loading) return;

    loading = true;

    try {

        const response = await fetch(
            '?action=fetch&after=' + lastId
        );

        if (!response.ok) return;

        const data = await response.json();

        data.forEach(msg => {

            const div = document.createElement('div');

            div.className = 'message';
            div.dataset.id = msg.id;
            div.dataset.senderId = msg.sender_id || '';
            div.dataset.color = msg.sender_color || '';
            div.dataset.position = msg.bubble_position || '';

            div.innerHTML =
                '<div>' +
                escapeHTML(msg.message).replace(/\n/g, '<br>') +
                '</div>' +
                '<small>' +
                escapeHTML(msg.created_at) +
                '</small>';

            if (msg.sender_id) {
                styleMessage(
                    div,
                    msg.sender_id,
                    msg.sender_color,
                    msg.bubble_position
                );
            }

            messages.appendChild(div);

            lastId = Math.max(lastId, Number(msg.id));
        });

        if (data.length) {
            messages.scrollTop = messages.scrollHeight;
        }

    } catch (error) {
        console.error('Chat update failed', error);

    } finally {
        loading = false;
    }
}


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

    try {

        const response = await fetch('', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();

        if (result.success) {

            messageInput.value = '';

            await loadMessages();

            messages.scrollTop = messages.scrollHeight;

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

messages.scrollTop = messages.scrollHeight;

</script>

</body>
</html>
