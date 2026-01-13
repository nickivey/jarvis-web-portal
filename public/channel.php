<?php
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../jwt.php';
require_once __DIR__ . '/../helpers.php';

if (!isset($_SESSION['username'])) { header('Location: login.php'); exit; }
$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) { session_destroy(); header('Location: login.php'); exit; }
$u = jarvis_user_by_id($uid);
if (!$u) { session_destroy(); header('Location: login.php'); exit; }

$defaultChannel = 'local:rhats';
$username = $u['username'] ?? 'user';
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Channels & Messaging • JARVIS Communications | Simple Functioning Solutions</title>
  <meta name="description" content="Real-time messaging and channel communication through JARVIS. Collaborate seamlessly with integrated Slack-like messaging. Simple Functioning Solutions, Orlando." />
  <meta name="keywords" content="messaging platform, channels, real-time communication, collaboration" />
  <meta name="author" content="Simple Functioning Solutions" />
  <link rel="stylesheet" href="/style.css">
  <style>
    * { box-sizing: border-box; }
    
    /* Slack-like Layout */
    .slack-container {
      display: flex;
      height: calc(100vh - 80px);
      background: #1a1d21;
      border-radius: 8px;
      overflow: hidden;
      box-shadow: 0 8px 30px rgba(0,0,0,0.3);
    }
    
    /* Sidebar */
    .slack-sidebar {
      width: 260px;
      background: linear-gradient(180deg, #1a1d21 0%, #232629 100%);
      border-right: 1px solid rgba(255,255,255,0.06);
      display: flex;
      flex-direction: column;
    }
    
    .slack-header {
      padding: 20px 16px;
      border-bottom: 1px solid rgba(255,255,255,0.06);
    }
    
    .workspace-name {
      font-size: 1.1rem;
      font-weight: 700;
      color: #fff;
      margin-bottom: 4px;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    
    .user-badge {
      display: flex;
      align-items: center;
      gap: 8px;
      margin-top: 8px;
      padding: 6px 8px;
      border-radius: 6px;
      background: rgba(255,255,255,0.03);
      font-size: 0.9rem;
    }
    
    .user-status {
      width: 10px;
      height: 10px;
      border-radius: 50%;
      background: #2eb67d;
    }
    
    /* Channels List */
    .channels-section {
      flex: 1;
      overflow-y: auto;
      padding: 12px 0;
    }
    
    .section-header {
      padding: 8px 16px;
      font-size: 0.8rem;
      font-weight: 600;
      color: rgba(255,255,255,0.5);
      text-transform: uppercase;
      letter-spacing: 0.5px;
      display: flex;
      align-items: center;
      gap: 6px;
      cursor: pointer;
      user-select: none;
    }
    
    .section-header:hover {
      color: rgba(255,255,255,0.7);
    }
    
    .channel-item {
      padding: 6px 16px;
      margin: 0 8px;
      border-radius: 6px;
      cursor: pointer;
      transition: background 0.15s ease;
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 0.95rem;
      color: rgba(255,255,255,0.7);
    }
    
    .channel-item:hover {
      background: rgba(255,255,255,0.08);
      color: #fff;
    }
    
    .channel-item.active {
      background: rgba(29, 155, 209, 0.15);
      color: #fff;
      font-weight: 500;
    }
    
    .channel-icon {
      opacity: 0.7;
      font-size: 1rem;
    }
    
    .new-channel-btn {
      padding: 8px 16px;
      margin: 8px;
      background: rgba(255,255,255,0.05);
      border: 1px solid rgba(255,255,255,0.1);
      border-radius: 6px;
      color: rgba(255,255,255,0.7);
      cursor: pointer;
      font-size: 0.9rem;
      text-align: center;
      transition: all 0.2s ease;
    }
    
    .new-channel-btn:hover {
      background: rgba(255,255,255,0.1);
      color: #fff;
    }
    
    /* Main Chat Area */
    .slack-main {
      flex: 1;
      display: flex;
      flex-direction: column;
      background: #121417;
    }
    
    /* Channel Header */
    .channel-header {
      padding: 16px 24px;
      border-bottom: 1px solid rgba(255,255,255,0.06);
      display: flex;
      align-items: center;
      justify-content: space-between;
      background: #1a1d21;
    }
    
    .channel-title {
      display: flex;
      align-items: center;
      gap: 10px;
    }
    
    .channel-title h2 {
      margin: 0;
      font-size: 1.2rem;
      font-weight: 700;
      color: #fff;
    }
    
    .channel-topic {
      font-size: 0.85rem;
      color: rgba(255,255,255,0.5);
    }
    
    .channel-actions {
      display: flex;
      gap: 12px;
    }
    
    .header-btn {
      padding: 6px 12px;
      background: rgba(255,255,255,0.05);
      border: 1px solid rgba(255,255,255,0.1);
      border-radius: 6px;
      color: rgba(255,255,255,0.7);
      font-size: 0.85rem;
      cursor: pointer;
      transition: all 0.15s ease;
    }
    
    .header-btn:hover {
      background: rgba(255,255,255,0.1);
      color: #fff;
    }
    
    /* Messages Area */
    .messages-container {
      flex: 1;
      overflow-y: auto;
      padding: 20px 24px;
      display: flex;
      flex-direction: column;
      gap: 4px;
    }
    
    .message-group {
      display: flex;
      gap: 12px;
      padding: 8px 12px;
      border-radius: 6px;
      transition: background 0.15s ease;
    }
    
    .message-group:hover {
      background: rgba(255,255,255,0.03);
    }
    
    .message-avatar {
      width: 38px;
      height: 38px;
      border-radius: 6px;
      background: linear-gradient(135deg, #d946ef 0%, #ec4899 100%);
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      font-size: 1rem;
      color: #fff;
      flex-shrink: 0;
    }
    
    .message-content {
      flex: 1;
      min-width: 0;
    }
    
    .message-header {
      display: flex;
      align-items: baseline;
      gap: 8px;
      margin-bottom: 4px;
    }
    
    .message-author {
      font-weight: 700;
      font-size: 0.95rem;
      color: #fff;
    }
    
    .message-time {
      font-size: 0.75rem;
      color: rgba(255,255,255,0.4);
    }
    
    .message-text {
      color: rgba(255,255,255,0.9);
      font-size: 0.95rem;
      line-height: 1.5;
      word-wrap: break-word;
    }
    
    .message-text a {
      color: #1d9bd1;
      text-decoration: none;
    }
    
    .message-text a:hover {
      text-decoration: underline;
    }
    
    .mention {
      background: rgba(29, 155, 209, 0.15);
      color: #1d9bd1;
      padding: 2px 4px;
      border-radius: 3px;
      font-weight: 500;
    }
    
    .hashtag {
      color: #1d9bd1;
      cursor: pointer;
      font-weight: 500;
    }
    
    .hashtag:hover {
      text-decoration: underline;
    }
    
    .message-actions {
      margin-top: 6px;
      display: flex;
      gap: 8px;
    }
    
    .msg-action-btn {
      padding: 4px 10px;
      background: rgba(255,255,255,0.05);
      border: 1px solid rgba(255,255,255,0.1);
      border-radius: 4px;
      color: rgba(255,255,255,0.6);
      font-size: 0.75rem;
      cursor: pointer;
      transition: all 0.15s ease;
    }
    
    .msg-action-btn:hover {
      background: rgba(255,255,255,0.1);
      color: #fff;
    }
    
    /* Message Input */
    .message-input-container {
      padding: 20px 24px;
      border-top: 1px solid rgba(255,255,255,0.06);
      background: #1a1d21;
    }
    
    .message-input-wrapper {
      background: #121417;
      border: 2px solid rgba(255,255,255,0.1);
      border-radius: 8px;
      transition: border-color 0.2s ease;
    }
    
    .message-input-wrapper:focus-within {
      border-color: rgba(29, 155, 209, 0.5);
    }
    
    .message-input {
      width: 100%;
      min-height: 44px;
      max-height: 200px;
      padding: 12px 16px;
      background: transparent;
      border: none;
      color: #fff;
      font-size: 0.95rem;
      font-family: inherit;
      resize: none;
      outline: none;
    }
    
    .message-input::placeholder {
      color: rgba(255,255,255,0.4);
    }
    
    .input-toolbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 8px 12px;
      border-top: 1px solid rgba(255,255,255,0.06);
    }
    
    .input-actions {
      display: flex;
      gap: 8px;
    }
    
    .input-btn {
      width: 32px;
      height: 32px;
      border-radius: 6px;
      background: rgba(255,255,255,0.05);
      border: none;
      color: rgba(255,255,255,0.6);
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: all 0.15s ease;
      font-size: 1.1rem;
    }
    
    .input-btn:hover {
      background: rgba(255,255,255,0.1);
      color: #fff;
    }
    
    .send-btn {
      padding: 6px 16px;
      background: linear-gradient(135deg, #d946ef 0%, #ec4899 100%);
      border: none;
      border-radius: 6px;
      color: #fff;
      font-weight: 600;
      font-size: 0.9rem;
      cursor: pointer;
      transition: all 0.2s ease;
    }
    
    .send-btn:hover {
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(217, 70, 239, 0.4);
    }
    
    .send-btn:disabled {
      opacity: 0.5;
      cursor: not-allowed;
      transform: none;
    }
    
    /* Date Divider */
    .date-divider {
      display: flex;
      align-items: center;
      gap: 16px;
      margin: 20px 0 12px;
    }
    
    .date-divider-line {
      flex: 1;
      height: 1px;
      background: rgba(255,255,255,0.1);
    }
    
    .date-divider-text {
      font-size: 0.8rem;
      font-weight: 600;
      color: rgba(255,255,255,0.5);
      padding: 4px 12px;
      background: rgba(255,255,255,0.05);
      border-radius: 20px;
      border: 1px solid rgba(255,255,255,0.1);
    }
    
    /* Loading State */
    .loading-messages {
      text-align: center;
      padding: 40px;
      color: rgba(255,255,255,0.5);
    }
    
    /* Emoji Picker (Placeholder) */
    .emoji-picker {
      position: absolute;
      bottom: 100%;
      right: 0;
      margin-bottom: 8px;
      background: #1a1d21;
      border: 1px solid rgba(255,255,255,0.1);
      border-radius: 8px;
      padding: 16px;
      box-shadow: 0 8px 24px rgba(0,0,0,0.3);
      display: none;
      z-index: 1000;
    }
    
    .emoji-picker.active {
      display: block;
    }
    
    .emoji-grid {
      display: grid;
      grid-template-columns: repeat(8, 1fr);
      gap: 4px;
      max-width: 320px;
    }
    
    .emoji-item {
      width: 32px;
      height: 32px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.5rem;
      cursor: pointer;
      border-radius: 4px;
      transition: background 0.15s ease;
    }
    
    .emoji-item:hover {
      background: rgba(255,255,255,0.1);
    }
    
    /* File Upload Preview */
    .file-preview {
      padding: 12px;
      background: rgba(255,255,255,0.03);
      border-radius: 6px;
      display: none;
      align-items: center;
      gap: 12px;
      margin-bottom: 8px;
    }
    
    .file-preview.active {
      display: flex;
    }
    
    .file-preview-icon {
      font-size: 2rem;
    }
    
    .file-preview-info {
      flex: 1;
    }
    
    .file-preview-name {
      font-size: 0.9rem;
      color: #fff;
      margin-bottom: 4px;
    }
    
    .file-preview-size {
      font-size: 0.75rem;
      color: rgba(255,255,255,0.5);
    }
    
    .file-preview-remove {
      color: rgba(255,255,255,0.6);
      cursor: pointer;
      font-size: 1.2rem;
    }
    
    .file-preview-remove:hover {
      color: #ef4444;
    }
    
    /* Scrollbar Styling */
    .messages-container::-webkit-scrollbar,
    .channels-section::-webkit-scrollbar {
      width: 8px;
    }
    
    .messages-container::-webkit-scrollbar-track,
    .channels-section::-webkit-scrollbar-track {
      background: rgba(0,0,0,0.2);
    }
    
    .messages-container::-webkit-scrollbar-thumb,
    .channels-section::-webkit-scrollbar-thumb {
      background: rgba(255,255,255,0.2);
      border-radius: 4px;
    }
    
    .messages-container::-webkit-scrollbar-thumb:hover,
    .channels-section::-webkit-scrollbar-thumb:hover {
      background: rgba(255,255,255,0.3);
    }
    
    /* Empty State */
    .empty-state {
      text-align: center;
      padding: 60px 24px;
      color: rgba(255,255,255,0.5);
    }
    
    .empty-state-icon {
      font-size: 4rem;
      margin-bottom: 16px;
      opacity: 0.3;
    }
    
    .empty-state h3 {
      color: #fff;
      margin-bottom: 8px;
    }
    
    /* Image Annotation Modal */
    .annotation-modal {
      position: fixed;
      inset: 0;
      z-index: 9999;
      background: rgba(0, 0, 0, 0.95);
      display: none;
      align-items: center;
      justify-content: center;
      padding: 20px;
    }
    
    .annotation-modal.active {
      display: flex;
    }
    
    .annotation-container {
      background: #1a1d21;
      border-radius: 12px;
      border: 1px solid rgba(255,255,255,0.1);
      max-width: 90vw;
      max-height: 90vh;
      display: flex;
      flex-direction: column;
      box-shadow: 0 20px 60px rgba(0,0,0,0.8);
    }
    
    .annotation-header {
      padding: 16px 20px;
      border-bottom: 1px solid rgba(255,255,255,0.1);
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    
    .annotation-header h3 {
      margin: 0;
      font-size: 1.1rem;
      color: #fff;
    }
    
    .annotation-close {
      background: rgba(255,255,255,0.05);
      border: 1px solid rgba(255,255,255,0.1);
      color: #fff;
      width: 32px;
      height: 32px;
      border-radius: 6px;
      font-size: 1.2rem;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    
    .annotation-close:hover {
      background: rgba(255,255,255,0.1);
    }
    
    .annotation-body {
      padding: 20px;
      display: flex;
      gap: 20px;
      overflow: auto;
    }
    
    .annotation-canvas-wrapper {
      position: relative;
      background: #000;
      border-radius: 8px;
      overflow: hidden;
    }
    
    #annotationCanvas {
      display: block;
      max-width: 100%;
      max-height: calc(90vh - 200px);
      cursor: crosshair;
    }
    
    .annotation-toolbar {
      min-width: 200px;
      display: flex;
      flex-direction: column;
      gap: 16px;
    }
    
    .tool-group {
      background: rgba(255,255,255,0.03);
      border: 1px solid rgba(255,255,255,0.06);
      border-radius: 8px;
      padding: 12px;
    }
    
    .tool-group-title {
      font-size: 0.75rem;
      font-weight: 600;
      color: rgba(255,255,255,0.5);
      text-transform: uppercase;
      letter-spacing: 0.5px;
      margin-bottom: 8px;
    }
    
    .tool-btn {
      width: 100%;
      padding: 8px 12px;
      background: rgba(255,255,255,0.05);
      border: 1px solid rgba(255,255,255,0.1);
      border-radius: 6px;
      color: rgba(255,255,255,0.7);
      cursor: pointer;
      font-size: 0.9rem;
      margin-bottom: 6px;
      display: flex;
      align-items: center;
      gap: 8px;
      transition: all 0.15s ease;
    }
    
    .tool-btn:hover {
      background: rgba(255,255,255,0.1);
      color: #fff;
    }
    
    .tool-btn.active {
      background: rgba(29, 155, 209, 0.2);
      border-color: rgba(29, 155, 209, 0.4);
      color: #fff;
    }
    
    .color-palette {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 8px;
    }
    
    .color-btn {
      width: 100%;
      aspect-ratio: 1;
      border-radius: 6px;
      border: 2px solid transparent;
      cursor: pointer;
      transition: all 0.15s ease;
    }
    
    .color-btn:hover {
      transform: scale(1.1);
    }
    
    .color-btn.active {
      border-color: #fff;
      box-shadow: 0 0 0 2px rgba(255,255,255,0.3);
    }
    
    .brush-size {
      width: 100%;
      margin-top: 8px;
    }
    
    .annotation-footer {
      padding: 16px 20px;
      border-top: 1px solid rgba(255,255,255,0.1);
      display: flex;
      justify-content: space-between;
      gap: 12px;
    }
    
    .annotation-footer .btn {
      padding: 10px 20px;
      border-radius: 6px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.2s ease;
    }
    
    .btn-cancel {
      background: rgba(255,255,255,0.05);
      border: 1px solid rgba(255,255,255,0.1);
      color: rgba(255,255,255,0.7);
    }
    
    .btn-cancel:hover {
      background: rgba(255,255,255,0.1);
      color: #fff;
    }
    
    .btn-save {
      background: linear-gradient(135deg, #d946ef 0%, #ec4899 100%);
      border: none;
      color: #fff;
    }
    
    .btn-save:hover {
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(217, 70, 239, 0.4);
    }

    /* Responsive */
    @media (max-width: 768px) {
      .slack-sidebar {
        width: 200px;
      }
      
      .channel-header {
        padding: 12px 16px;
      }
      
      .messages-container {
        padding: 12px 16px;
      }
      
      .message-input-container {
        padding: 12px 16px;
      }
      
      .annotation-body {
        flex-direction: column;
      }
      
      .annotation-toolbar {
        min-width: auto;
      }
    }
  </style>
</head>
<body>
<?php include __DIR__ . '/navbar.php'; ?>
<div class="container" style="max-width: 1800px; padding-top: 20px;">
  <div class="slack-container">
    <!-- Sidebar -->
    <div class="slack-sidebar">
      <div class="slack-header">
        <div class="workspace-name">
          <span>💬</span> JARVIS Channels
        </div>
        <div class="user-badge">
          <div class="user-status"></div>
          <span><?php echo htmlspecialchars($username); ?></span>
        </div>
      </div>
      
      <div class="channels-section">
        <div class="section-header">
          <span>▼</span> Channels
        </div>
        <div class="channel-item active" data-channel="local:rhats">
          <span class="channel-icon">#</span>
          <span>rhats</span>
        </div>
        <div class="channel-item" data-channel="local:general">
          <span class="channel-icon">#</span>
          <span>general</span>
        </div>
        <div class="channel-item" data-channel="local:jarvis">
          <span class="channel-icon">#</span>
          <span>jarvis</span>
        </div>
        <div class="channel-item" data-channel="local:projects">
          <span class="channel-icon">#</span>
          <span>projects</span>
        </div>
        
        <div class="new-channel-btn" id="newChannelBtn">
          + Add Channel
        </div>
        
        <div class="section-header" style="margin-top: 20px;">
          <span>▼</span> Direct Messages
        </div>
        <div class="channel-item" data-channel="dm:jarvis">
          <span class="channel-icon">🤖</span>
          <span>JARVIS (AI)</span>
        </div>
        <div class="channel-item" data-channel="dm:self">
          <span class="channel-icon">💬</span>
          <span>Notes to Self</span>
        </div>
      </div>
    </div>
    
    <!-- Main Chat Area -->
    <div class="slack-main">
      <!-- Channel Header -->
      <div class="channel-header">
        <div class="channel-title">
          <h2 id="currentChannelName"># rhats</h2>
          <span class="channel-topic" id="channelTopic">Team discussions and project updates</span>
        </div>
        <div class="channel-actions">
          <button class="header-btn" id="channelInfoBtn">ℹ️ Details</button>
          <button class="header-btn" id="searchBtn">🔍 Search</button>
        </div>
      </div>
      
      <!-- Messages -->
      <div class="messages-container" id="messagesContainer">
        <div class="loading-messages">Loading messages...</div>
      </div>
      
      <!-- Message Input -->
      <div class="message-input-container">
        <div class="file-preview" id="filePreview">
          <div class="file-preview-icon">📎</div>
          <div class="file-preview-info">
            <div class="file-preview-name" id="fileName"></div>
            <div class="file-preview-size" id="fileSize"></div>
          </div>
          <div class="file-preview-remove" id="removeFile">×</div>
        </div>
        
        <div class="message-input-wrapper">
          <textarea 
            id="messageInput" 
            class="message-input" 
            placeholder="Message #rhats (use @username for mentions, #hashtags for tags)"
            rows="1"
          ></textarea>
          
          <div class="input-toolbar">
            <div class="input-actions">
              <button class="input-btn" id="attachBtn" title="Attach file">📎</button>
              <button class="input-btn" id="emojiBtn" title="Add emoji">😊</button>
              <button class="input-btn" id="mentionBtn" title="Mention someone">@</button>
              <button class="input-btn" id="hashtagBtn" title="Add hashtag">#</button>
              <input type="file" id="fileInput" style="display: none;" accept="image/*,video/*,.pdf,.doc,.docx,.txt">
            </div>
            <button class="send-btn" id="sendBtn" disabled>
              Send
            </button>
          </div>
        </div>
        
        <!-- Emoji Picker (Hidden by default) -->
        <div class="emoji-picker" id="emojiPicker">
          <div class="emoji-grid">
            <div class="emoji-item" data-emoji="😊">😊</div>
            <div class="emoji-item" data-emoji="👍">👍</div>
            <div class="emoji-item" data-emoji="❤️">❤️</div>
            <div class="emoji-item" data-emoji="🎉">🎉</div>
            <div class="emoji-item" data-emoji="🔥">🔥</div>
            <div class="emoji-item" data-emoji="✅">✅</div>
            <div class="emoji-item" data-emoji="⚠️">⚠️</div>
            <div class="emoji-item" data-emoji="💡">💡</div>
            <div class="emoji-item" data-emoji="🚀">🚀</div>
            <div class="emoji-item" data-emoji="💯">💯</div>
            <div class="emoji-item" data-emoji="👀">👀</div>
            <div class="emoji-item" data-emoji="🤔">🤔</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Image Annotation Modal -->
<div class="annotation-modal" id="annotationModal">
  <div class="annotation-container">
    <div class="annotation-header">
      <h3 id="annotationTitle">✏️ Draw on Image</h3>
      <button class="annotation-close" id="closeAnnotation">×</button>
    </div>
    <div class="annotation-body">
      <div class="annotation-canvas-wrapper">
        <video id="annotationVideo" style="display:none; max-width:100%; max-height:calc(90vh - 200px)" controls></video>
        <canvas id="annotationCanvas"></canvas>
        <div class="video-controls" id="videoControls" style="display:none; margin-top:12px">
          <button class="tool-btn" id="captureFrame" style="width:100%">📸 Capture This Frame to Draw</button>
          <div style="margin-top:8px; font-size:0.85rem; color:rgba(255,255,255,0.5); text-align:center" id="videoTime"></div>
        </div>
      </div>
      <div class="annotation-toolbar">
        <div class="tool-group">
          <div class="tool-group-title">Tools</div>
          <button class="tool-btn active" data-tool="draw">✏️ Draw</button>
          <button class="tool-btn" data-tool="text">📝 Text</button>
          <button class="tool-btn" data-tool="arrow">➡️ Arrow</button>
          <button class="tool-btn" data-tool="highlight">🖍️ Highlight</button>
        </div>
        <div class="tool-group">
          <div class="tool-group-title">Colors</div>
          <div class="color-palette">
            <button class="color-btn active" style="background: #ef4444" data-color="#ef4444"></button>
            <button class="color-btn" style="background: #f59e0b" data-color="#f59e0b"></button>
            <button class="color-btn" style="background: #10b981" data-color="#10b981"></button>
            <button class="color-btn" style="background: #3b82f6" data-color="#3b82f6"></button>
            <button class="color-btn" style="background: #8b5cf6" data-color="#8b5cf6"></button>
            <button class="color-btn" style="background: #ec4899" data-color="#ec4899"></button>
            <button class="color-btn" style="background: #ffffff" data-color="#ffffff"></button>
            <button class="color-btn" style="background: #000000" data-color="#000000"></button>
          </div>
        </div>
        <div class="tool-group">
          <div class="tool-group-title">Brush Size</div>
          <input type="range" class="brush-size" id="brushSize" min="1" max="20" value="3">
          <div style="text-align: center; color: rgba(255,255,255,0.5); font-size: 0.8rem; margin-top: 4px;" id="brushSizeLabel">3px</div>
        </div>
        <div class="tool-group">
          <button class="tool-btn" id="clearCanvas">🗑️ Clear All</button>
          <button class="tool-btn" id="undoCanvas">↶ Undo</button>
        </div>
      </div>
    </div>
    <div class="annotation-footer">
      <button class="btn btn-cancel" id="cancelAnnotation">Cancel</button>
      <button class="btn btn-save" id="saveAnnotation">Use This Image</button>
    </div>
  </div>
</div>

<script>
// ===== SLACK-LIKE CHANNEL WIDGET =====
const CURRENT_USER = <?php echo json_encode($username); ?>;
const UID = <?php echo (int)$uid; ?>;
const IS_ADMIN = <?php echo ($u['role'] ?? '') === 'admin' ? 'true' : 'false'; ?>;

let currentChannel = 'local:rhats';
let selectedFile = null;
let messagesByChannel = {};

// Utility: Get initials from username
function getUserInitials(username) {
  return username.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2);
}

// Utility: Format timestamp
function formatTimestamp(dateStr) {
  const date = new Date(dateStr);
  const now = new Date();
  const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
  const msgDate = new Date(date.getFullYear(), date.getMonth(), date.getDate());
  
  if (msgDate.getTime() === today.getTime()) {
    return date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true });
  } else {
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
  }
}

// Utility: Format message text with mentions and hashtags
function formatMessageText(text) {
  return text
    .replace(/@([A-Za-z0-9_\-]+)/g, '<span class="mention">@$1</span>')
    .replace(/(#([A-Za-z0-9_\-]+))/g, '<span class="hashtag" data-tag="$2">$1</span>')
    .replace(/\n/g, '<br>');
}

// Load messages for current channel
async function loadMessages() {
  const container = document.getElementById('messagesContainer');
  container.innerHTML = '<div class="loading-messages">Loading messages...</div>';
  
  try {
    const params = new URLSearchParams({ channel: currentChannel });
    const response = await fetch('/api/messages?' + params.toString(), {
      headers: { 'Authorization': 'Bearer ' + (window.jarvisJwt || '') }
    });
    
    if (!response.ok) throw new Error('Failed to load messages');
    
    const data = await response.json();
    const messages = data.messages || [];
    
    messagesByChannel[currentChannel] = messages;
    
    if (messages.length === 0) {
      container.innerHTML = `
        <div class="empty-state">
          <div class="empty-state-icon">💬</div>
          <h3>No messages yet</h3>
          <p>Be the first to message this channel!</p>
        </div>
      `;
      return;
    }
    
    renderMessages(messages);
    
  } catch (error) {
    console.error('Error loading messages:', error);
    container.innerHTML = '<div class="loading-messages" style="color: #ef4444;">Failed to load messages</div>';
  }
}

// Render messages with Slack-like grouping
function renderMessages(messages) {
  const container = document.getElementById('messagesContainer');
  container.innerHTML = '';
  
  let lastDate = null;
  let lastAuthor = null;
  let messageGroup = null;
  
  messages.forEach((msg, idx) => {
    const msgDate = new Date(msg.created_at);
    const msgDateStr = msgDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    
    // Add date divider if needed
    if (lastDate !== msgDateStr) {
      const divider = document.createElement('div');
      divider.className = 'date-divider';
      divider.innerHTML = `
        <div class="date-divider-line"></div>
        <div class="date-divider-text">${msgDateStr}</div>
        <div class="date-divider-line"></div>
      `;
      container.appendChild(divider);
      lastDate = msgDateStr;
      lastAuthor = null;
    }
    
    // Group messages from same author
    if (lastAuthor !== msg.username) {
      messageGroup = document.createElement('div');
      messageGroup.className = 'message-group';
      container.appendChild(messageGroup);
      lastAuthor = msg.username;
    }
    
    // Create message element
    const msgEl = document.createElement('div');
    msgEl.style.display = 'flex';
    msgEl.style.gap = '12px';
    msgEl.dataset.messageId = msg.id;
    
    const author = msg.username || CURRENT_USER;
    const initials = getUserInitials(author);
    const isOwnMessage = msg.user_id === UID;
    
    msgEl.innerHTML = `
      <div class="message-avatar" style="background: ${getAvatarColor(author)}">${initials}</div>
      <div class="message-content">
        <div class="message-header">
          <span class="message-author">${author}</span>
          <span class="message-time">${formatTimestamp(msg.created_at)}</span>
        </div>
        <div class="message-text">${formatMessageText(msg.message_text)}</div>
        ${msg.metadata ? `<div class="message-meta" style="font-size:0.75rem;color:rgba(255,255,255,0.4);margin-top:4px">${JSON.stringify(msg.metadata).substring(0, 60)}...</div>` : ''}
        <div class="message-actions">
          ${isOwnMessage || IS_ADMIN ? `<button class="msg-action-btn delete-msg" data-id="${msg.id}">Delete</button>` : ''}
          <button class="msg-action-btn reply-msg" data-author="${author}">Reply</button>
          <button class="msg-action-btn reaction-msg">😊 React</button>
        </div>
      </div>
    `;
    
    messageGroup.appendChild(msgEl);
  });
  
  // Wire up event handlers
  wireMessageHandlers();
  
  // Scroll to bottom
  container.scrollTop = container.scrollHeight;
}

// Wire up message action handlers
function wireMessageHandlers() {
  // Delete buttons
  document.querySelectorAll('.delete-msg').forEach(btn => {
    btn.addEventListener('click', async (e) => {
      const msgId = e.target.dataset.id;
      if (!confirm('Delete this message?')) return;
      
      try {
        const response = await fetch(`/api/messages/${msgId}`, {
          method: 'DELETE',
          headers: { 'Authorization': 'Bearer ' + (window.jarvisJwt || '') }
        });
        
        if (response.ok) {
          // Audit log for message deletion
          if (window.jarvisApi && window.jarvisApi.auditLog) {
            window.jarvisApi.auditLog('CHANNEL_MESSAGE_DELETED_BY_USER', 'channel', {
              message_id: msgId,
              channel: currentChannel,
              timestamp: new Date().toISOString()
            });
          }
          loadMessages();
        }
      } catch (error) {
        console.error('Error deleting message:', error);
      }
    });
  });
  
  // Reply buttons
  document.querySelectorAll('.reply-msg').forEach(btn => {
    btn.addEventListener('click', (e) => {
      const author = e.target.dataset.author;
      const input = document.getElementById('messageInput');
      input.value = `@${author} `;
      input.focus();
      
      // Audit log for reply action
      if (window.jarvisApi && window.jarvisApi.auditLog) {
        window.jarvisApi.auditLog('CHANNEL_REPLY_INITIATED', 'channel', {
          recipient: author,
          channel: currentChannel,
          timestamp: new Date().toISOString()
        });
      }
    });
  });
  
  // Hashtag clicks
  document.querySelectorAll('.hashtag').forEach(el => {
    el.addEventListener('click', (e) => {
      const tag = e.target.dataset.tag;
      const input = document.getElementById('messageInput');
      input.value += `#${tag} `;
      input.focus();
      
      // Audit log for hashtag interaction
      if (window.jarvisApi && window.jarvisApi.auditLog) {
        window.jarvisApi.auditLog('CHANNEL_HASHTAG_CLICKED', 'channel', {
          hashtag: tag,
          channel: currentChannel,
          timestamp: new Date().toISOString()
        });
      }
    });
  });
  
  // Reaction buttons
  document.querySelectorAll('.reaction-msg').forEach(btn => {
    btn.addEventListener('click', (e) => {
      const msgEl = e.target.closest('.message-item');
      const msgId = msgEl ? msgEl.dataset.id : 'unknown';
      
      // Audit log for reaction
      if (window.jarvisApi && window.jarvisApi.auditLog) {
        window.jarvisApi.auditLog('CHANNEL_REACTION_INITIATED', 'channel', {
          message_id: msgId,
          channel: currentChannel,
          timestamp: new Date().toISOString()
        });
      }
    });
  });
}

// Get consistent avatar color
function getAvatarColor(username) {
  const colors = ['#d946ef', '#ec4899', '#f43f5e', '#f97316', '#eab308', '#84cc16', '#22c55e', '#10b981', '#14b8a6', '#06b6d4', '#0ea5e9', '#3b82f6', '#6366f1', '#8b5cf6'];
  const hash = username.split('').reduce((h, c) => h + c.charCodeAt(0), 0);
  return colors[hash % colors.length];
}

// Send message
async function sendMessage() {
  const input = document.getElementById('messageInput');
  const message = input.value.trim();
  
  if (!message) return;
  
  const sendBtn = document.getElementById('sendBtn');
  sendBtn.disabled = true;
  
  try {
    // Parse metadata for audit tracking
    const tags = [];
    const mentions = [];
    const hashtagRegex = /#([A-Za-z0-9_\-]+)/g;
    const mentionRegex = /@([A-Za-z0-9_\-]+)/g;
    
    let match;
    while ((match = hashtagRegex.exec(message)) !== null) {
      tags.push(match[1]);
    }
    while ((match = mentionRegex.exec(message)) !== null) {
      mentions.push(match[1]);
    }
    
    const response = await fetch('/api/messages', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': 'Bearer ' + (window.jarvisJwt || '')
      },
      body: JSON.stringify({
        channel: currentChannel,
        message: message,
        provider: 'local',
        metadata: {
          uploaded_file: selectedFile ? selectedFile.name : null,
          client_timestamp: new Date().toISOString(),
          user_agent: navigator.userAgent,
          tags: tags.length > 0 ? tags : undefined,
          mentions: mentions.length > 0 ? mentions : undefined,
          file_size: selectedFile ? selectedFile.size : null
        }
      })
    });
    
    if (response.ok) {
      const data = await response.json();
      
      // Log channel activity for audit trail
      if (window.jarvisApi && window.jarvisApi.auditLog) {
        window.jarvisApi.auditLog('CHANNEL_MESSAGE_SENT', 'channel', {
          channel: currentChannel,
          message_length: message.length,
          tags: tags,
          mentions: mentions,
          has_file: !!selectedFile,
          file_name: selectedFile ? selectedFile.name : null,
          file_size: selectedFile ? selectedFile.size : null,
          timestamp: new Date().toISOString()
        });
      }
      
      input.value = '';
      selectedFile = null;
      document.getElementById('filePreview').classList.remove('active');
      updateInputHeight();
      loadMessages();
    }
  } catch (error) {
    console.error('Error sending message:', error);
    alert('Failed to send message');
  } finally {
    sendBtn.disabled = input.value.trim() === '';
  }
}

// Update input height based on content
function updateInputHeight() {
  const input = document.getElementById('messageInput');
  input.style.height = 'auto';
  input.style.height = Math.min(input.scrollHeight, 200) + 'px';
  
  const sendBtn = document.getElementById('sendBtn');
  sendBtn.disabled = input.value.trim() === '';
}

// Switch channel
function switchChannel(channel) {
  currentChannel = channel;
  
  // Update active state
  document.querySelectorAll('.channel-item').forEach(el => {
    el.classList.remove('active');
  });
  document.querySelector(`[data-channel="${channel}"]`).classList.add('active');
  
  // Update channel name in header
  const channelName = channel.replace('local:', '').replace('dm:', '');
  const icon = channel.startsWith('dm:') ? (channelName === 'jarvis' ? '🤖' : '💬') : '#';
  document.getElementById('currentChannelName').textContent = `${icon} ${channelName}`;
  
  // Update input placeholder
  document.getElementById('messageInput').placeholder = `Message ${channel.startsWith('dm:') ? '@' : '#'}${channelName}...`;
  
  // Audit log for channel switch
  if (window.jarvisApi && window.jarvisApi.auditLog) {
    window.jarvisApi.auditLog('CHANNEL_SWITCHED', 'channel', {
      channel: channel,
      channel_type: channel.startsWith('dm:') ? 'direct_message' : 'group',
      timestamp: new Date().toISOString()
    });
  }
  
  loadMessages();
}

// ===== EVENT LISTENERS =====

// Channel selection
document.querySelectorAll('.channel-item').forEach(item => {
  item.addEventListener('click', () => {
    const channel = item.dataset.channel;
    switchChannel(channel);
  });
});

// Message input
const messageInput = document.getElementById('messageInput');
messageInput.addEventListener('input', updateInputHeight);
messageInput.addEventListener('keydown', (e) => {
  if (e.key === 'Enter' && e.ctrlKey) {
    sendMessage();
  }
});

// Send button
document.getElementById('sendBtn').addEventListener('click', sendMessage);

// Attach file
document.getElementById('attachBtn').addEventListener('click', () => {
  document.getElementById('fileInput').click();
});

document.getElementById('fileInput').addEventListener('change', (e) => {
  const file = e.target.files[0];
  if (file) {
    // Check if it's an image or video - open annotation modal
    if (file.type.startsWith('image/')) {
      openImageAnnotation(file);
    } else if (file.type.startsWith('video/')) {
      openVideoAnnotation(file);
    } else {
      // Non-image/video files go directly to preview
      selectedFile = file;
      document.getElementById('fileName').textContent = file.name;
      document.getElementById('fileSize').textContent = (file.size / 1024 / 1024).toFixed(2) + ' MB';
      document.getElementById('filePreview').classList.add('active');
      
      // Audit log for file attachment
      if (window.jarvisApi && window.jarvisApi.auditLog) {
        window.jarvisApi.auditLog('CHANNEL_FILE_ATTACHED', 'channel', {
          file_name: file.name,
          file_size: file.size,
          file_type: file.type,
          channel: currentChannel,
          timestamp: new Date().toISOString()
        });
      }
    }
  }
});

document.getElementById('removeFile').addEventListener('click', () => {
  selectedFile = null;
  document.getElementById('filePreview').classList.remove('active');
  document.getElementById('fileInput').value = '';
});

// Emoji picker
document.getElementById('emojiBtn').addEventListener('click', () => {
  const picker = document.getElementById('emojiPicker');
  picker.classList.toggle('active');
  
  if (picker.classList.contains('active')) {
    if (window.jarvisApi && window.jarvisApi.auditLog) {
      window.jarvisApi.auditLog('CHANNEL_EMOJI_PICKER_OPENED', 'channel', {
        channel: currentChannel,
        timestamp: new Date().toISOString()
      });
    }
  }
});

document.querySelectorAll('.emoji-item').forEach(item => {
  item.addEventListener('click', () => {
    const emoji = item.dataset.emoji;
    const input = document.getElementById('messageInput');
    input.value += emoji + ' ';
    input.focus();
    
    // Audit log for emoji selection
    if (window.jarvisApi && window.jarvisApi.auditLog) {
      window.jarvisApi.auditLog('CHANNEL_EMOJI_ADDED', 'channel', {
        emoji: emoji,
        channel: currentChannel,
        timestamp: new Date().toISOString()
      });
    }
    
    document.getElementById('emojiPicker').classList.remove('active');
    updateInputHeight();
  });
});

// Mention button
document.getElementById('mentionBtn').addEventListener('click', () => {
  const input = document.getElementById('messageInput');
  input.value += '@';
  input.focus();
  
  if (window.jarvisApi && window.jarvisApi.auditLog) {
    window.jarvisApi.auditLog('CHANNEL_MENTION_TRIGGERED', 'channel', {
      channel: currentChannel,
      timestamp: new Date().toISOString()
    });
  }
  
  updateInputHeight();
});

// Hashtag button
document.getElementById('hashtagBtn').addEventListener('click', () => {
  const input = document.getElementById('messageInput');
  input.value += '#';
  input.focus();
  
  if (window.jarvisApi && window.jarvisApi.auditLog) {
    window.jarvisApi.auditLog('CHANNEL_HASHTAG_TRIGGERED', 'channel', {
      channel: currentChannel,
      timestamp: new Date().toISOString()
    });
  }
  
  updateInputHeight();
});

// New channel button
document.getElementById('newChannelBtn').addEventListener('click', () => {
  const channelName = prompt('Enter new channel name (e.g., "meetings", "ideas"):');
  if (channelName && channelName.trim()) {
    const newChannel = 'local:' + channelName.trim().toLowerCase().replace(/[^a-z0-9-_]/g, '');
    
    // Audit log for channel creation
    if (window.jarvisApi && window.jarvisApi.auditLog) {
      window.jarvisApi.auditLog('CHANNEL_CREATED', 'channel', {
        channel: newChannel,
        display_name: channelName,
        timestamp: new Date().toISOString()
      });
    }
    
    switchChannel(newChannel);
    
    // Add to sidebar
    const newItem = document.createElement('div');
    newItem.className = 'channel-item active';
    newItem.dataset.channel = newChannel;
    newItem.innerHTML = `<span class="channel-icon">#</span><span>${channelName}</span>`;
    newItem.addEventListener('click', () => switchChannel(newChannel));
    
    document.querySelector('.channels-section').insertBefore(
      newItem,
      document.getElementById('newChannelBtn')
    );
  }
});

// ===== IMAGE ANNOTATION FUNCTIONALITY =====
let annotationCanvas, annotationCtx;
let annotationImage = null;
let originalFile = null;
let isDrawing = false;
let currentTool = 'draw';
let currentColor = '#ef4444';
let brushSize = 3;
let drawHistory = [];
let lastX = 0, lastY = 0;

function openImageAnnotation(file) {
  originalFile = file;
  const modal = document.getElementById('annotationModal');
  const video = document.getElementById('annotationVideo');
  const videoControls = document.getElementById('videoControls');
  annotationCanvas = document.getElementById('annotationCanvas');
  annotationCtx = annotationCanvas.getContext('2d');
  
  // Hide video elements, show canvas
  video.style.display = 'none';
  videoControls.style.display = 'none';
  annotationCanvas.style.display = 'block';
  document.getElementById('annotationTitle').textContent = '✏️ Draw on Image';
  
  // Load image
  const reader = new FileReader();
  reader.onload = (e) => {
    const img = new Image();
    img.onload = () => {
      annotationImage = img;
      
      // Set canvas size to match image
      const maxWidth = Math.min(window.innerWidth * 0.6, img.width);
      const maxHeight = Math.min(window.innerHeight * 0.6, img.height);
      const scale = Math.min(maxWidth / img.width, maxHeight / img.height, 1);
      
      annotationCanvas.width = img.width * scale;
      annotationCanvas.height = img.height * scale;
      
      // Draw image
      annotationCtx.drawImage(img, 0, 0, annotationCanvas.width, annotationCanvas.height);
      
      // Save initial state
      drawHistory = [annotationCanvas.toDataURL()];
      
      // Show modal
      modal.classList.add('active');
      
      // Audit
      if (window.jarvisApi && window.jarvisApi.auditLog) {
        window.jarvisApi.auditLog('CHANNEL_IMAGE_ANNOTATION_OPENED', 'channel', {
          file_name: file.name,
          timestamp: new Date().toISOString()
        });
      }
    };
    img.src = e.target.result;
  };
  reader.readAsDataURL(file);
}

function openVideoAnnotation(file) {
  originalFile = file;
  const modal = document.getElementById('annotationModal');
  const video = document.getElementById('annotationVideo');
  const videoControls = document.getElementById('videoControls');
  const videoTime = document.getElementById('videoTime');
  annotationCanvas = document.getElementById('annotationCanvas');
  annotationCtx = annotationCanvas.getContext('2d');
  
  // Show video, hide canvas initially
  video.style.display = 'block';
  videoControls.style.display = 'block';
  annotationCanvas.style.display = 'none';
  document.getElementById('annotationTitle').textContent = '🎬 Select Frame from Video';
  
  // Load video
  const reader = new FileReader();
  reader.onload = (e) => {
    video.src = e.target.result;
    video.load();
    
    // Update time display
    video.addEventListener('timeupdate', () => {
      const current = Math.floor(video.currentTime);
      const duration = Math.floor(video.duration) || 0;
      videoTime.textContent = `${current}s / ${duration}s - Pause and click "Capture Frame" to annotate`;
    });
    
    // Show modal
    modal.classList.add('active');
    
    // Audit
    if (window.jarvisApi && window.jarvisApi.auditLog) {
      window.jarvisApi.auditLog('CHANNEL_VIDEO_ANNOTATION_OPENED', 'channel', {
        file_name: file.name,
        timestamp: new Date().toISOString()
      });
    }
  };
  reader.readAsDataURL(file);
}

// Capture frame from video
document.getElementById('captureFrame')?.addEventListener('click', () => {
  const video = document.getElementById('annotationVideo');
  const canvas = document.getElementById('annotationCanvas');
  const ctx = annotationCtx;
  
  if (!video || !canvas || !ctx) return;
  
  // Set canvas size to match video
  const maxWidth = Math.min(window.innerWidth * 0.6, video.videoWidth);
  const maxHeight = Math.min(window.innerHeight * 0.6, video.videoHeight);
  const scale = Math.min(maxWidth / video.videoWidth, maxHeight / video.videoHeight, 1);
  
  canvas.width = video.videoWidth * scale;
  canvas.height = video.videoHeight * scale;
  
  // Draw current video frame to canvas
  ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
  
  // Create image from canvas for annotation
  const img = new Image();
  img.onload = () => {
    annotationImage = img;
    drawHistory = [canvas.toDataURL()];
    
    // Hide video, show canvas for annotation
    video.style.display = 'none';
    document.getElementById('videoControls').style.display = 'none';
    canvas.style.display = 'block';
    document.getElementById('annotationTitle').textContent = '✏️ Draw on Video Frame';
    
    // Audit
    if (window.jarvisApi && window.jarvisApi.auditLog) {
      window.jarvisApi.auditLog('CHANNEL_VIDEO_FRAME_CAPTURED', 'channel', {
        timestamp: new Date().toISOString(),
        video_time: Math.floor(video.currentTime)
      });
    }
  };
  img.src = canvas.toDataURL();
});

// Drawing functionality
annotationCanvas?.addEventListener('mousedown', (e) => {
  if (!annotationCanvas) return;
  isDrawing = true;
  const rect = annotationCanvas.getBoundingClientRect();
  lastX = e.clientX - rect.left;
  lastY = e.clientY - rect.top;
  
  if (currentTool === 'text') {
    const text = prompt('Enter text:');
    if (text) {
      annotationCtx.font = `${brushSize * 8}px Arial`;
      annotationCtx.fillStyle = currentColor;
      annotationCtx.fillText(text, lastX, lastY);
      saveState();
    }
    isDrawing = false;
  }
});

annotationCanvas?.addEventListener('mousemove', (e) => {
  if (!isDrawing || !annotationCanvas) return;
  
  const rect = annotationCanvas.getBoundingClientRect();
  const x = e.clientX - rect.left;
  const y = e.clientY - rect.top;
  
  if (currentTool === 'draw') {
    annotationCtx.beginPath();
    annotationCtx.moveTo(lastX, lastY);
    annotationCtx.lineTo(x, y);
    annotationCtx.strokeStyle = currentColor;
    annotationCtx.lineWidth = brushSize;
    annotationCtx.lineCap = 'round';
    annotationCtx.stroke();
  } else if (currentTool === 'highlight') {
    annotationCtx.beginPath();
    annotationCtx.moveTo(lastX, lastY);
    annotationCtx.lineTo(x, y);
    annotationCtx.strokeStyle = currentColor + '80'; // Add transparency
    annotationCtx.lineWidth = brushSize * 3;
    annotationCtx.lineCap = 'round';
    annotationCtx.stroke();
  }
  
  lastX = x;
  lastY = y;
});

annotationCanvas?.addEventListener('mouseup', () => {
  if (isDrawing) {
    isDrawing = false;
    saveState();
  }
});

annotationCanvas?.addEventListener('mouseleave', () => {
  isDrawing = false;
});

function saveState() {
  if (annotationCanvas) {
    drawHistory.push(annotationCanvas.toDataURL());
    if (drawHistory.length > 20) drawHistory.shift();
  }
}

// Tool selection
document.querySelectorAll('[data-tool]').forEach(btn => {
  btn.addEventListener('click', (e) => {
    document.querySelectorAll('[data-tool]').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    currentTool = btn.getAttribute('data-tool');
  });
});

// Color selection
document.querySelectorAll('.color-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.color-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    currentColor = btn.getAttribute('data-color');
  });
});

// Brush size
document.getElementById('brushSize')?.addEventListener('input', (e) => {
  brushSize = parseInt(e.target.value);
  document.getElementById('brushSizeLabel').textContent = brushSize + 'px';
});

// Clear canvas
document.getElementById('clearCanvas')?.addEventListener('click', () => {
  if (annotationCanvas && annotationImage) {
    annotationCtx.clearRect(0, 0, annotationCanvas.width, annotationCanvas.height);
    annotationCtx.drawImage(annotationImage, 0, 0, annotationCanvas.width, annotationCanvas.height);
    saveState();
  }
});

// Undo
document.getElementById('undoCanvas')?.addEventListener('click', () => {
  if (drawHistory.length > 1) {
    drawHistory.pop();
    const img = new Image();
    img.onload = () => {
      annotationCtx.clearRect(0, 0, annotationCanvas.width, annotationCanvas.height);
      annotationCtx.drawImage(img, 0, 0);
    };
    img.src = drawHistory[drawHistory.length - 1];
  }
});

// Cancel annotation
document.getElementById('cancelAnnotation')?.addEventListener('click', () => {
  document.getElementById('annotationModal').classList.remove('active');
  document.getElementById('fileInput').value = '';
});

document.getElementById('closeAnnotation')?.addEventListener('click', () => {
  document.getElementById('annotationModal').classList.remove('active');
  document.getElementById('fileInput').value = '';
});

// Save annotation
document.getElementById('saveAnnotation')?.addEventListener('click', () => {
  if (!annotationCanvas || !originalFile) return;
  
  // Convert canvas to blob
  annotationCanvas.toBlob((blob) => {
    // Create a new file from the blob
    const annotatedFile = new File([blob], originalFile.name, { type: 'image/png' });
    
    // Set as selected file
    selectedFile = annotatedFile;
    document.getElementById('fileName').textContent = annotatedFile.name + ' (annotated)';
    document.getElementById('fileSize').textContent = (annotatedFile.size / 1024 / 1024).toFixed(2) + ' MB';
    document.getElementById('filePreview').classList.add('active');
    
    // Close modal
    document.getElementById('annotationModal').classList.remove('active');
    
    // Audit log
    if (window.jarvisApi && window.jarvisApi.auditLog) {
      window.jarvisApi.auditLog('CHANNEL_IMAGE_ANNOTATED', 'channel', {
        file_name: originalFile.name,
        tool_used: currentTool,
        timestamp: new Date().toISOString()
      });
    }
  }, 'image/png');
});

// Initialize
document.addEventListener('DOMContentLoaded', () => {
  loadMessages();
  updateInputHeight();
});
</script>
</body></html>