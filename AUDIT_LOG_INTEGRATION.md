# Channel Widget Audit Log Integration

## Overview
Complete audit logging integration for the Slack-like channel widget, capturing all user interactions with timestamps and contextual metadata.

## Audit Events Logged

### Server-Side Audit Events (index.php)

| Event | Trigger | Metadata | Source |
|-------|---------|----------|--------|
| `CHANNEL_MSG` | Message sent via API | message_length, tags, mentions, recipient | POST /api/messages |
| `CHANNEL_MESSAGE_DELETED` | Message deleted via API | message_id, deleted_by | DELETE /api/messages/{id} |
| `MENTIONED` | User mentioned in message | mentioned_user_id, message_id | Message parsing |
| `EMAIL_NOTIFICATION_SENT` | Mention notification sent | recipient_user_id, sender_id | Mention handler |

### Client-Side Audit Events (public/channel.php)

| Event | Trigger | Metadata | Location |
|-------|---------|----------|----------|
| `CHANNEL_MESSAGE_SENT` | User sends message | channel, message_length, tags, mentions, file_info | sendMessage() |
| `CHANNEL_MESSAGE_DELETED_BY_USER` | User clicks delete button | message_id, channel | wireMessageHandlers() |
| `CHANNEL_REPLY_INITIATED` | User clicks reply button | recipient, channel | wireMessageHandlers() |
| `CHANNEL_HASHTAG_CLICKED` | User clicks hashtag | hashtag, channel | wireMessageHandlers() |
| `CHANNEL_REACTION_INITIATED` | User clicks react button | message_id, channel | wireMessageHandlers() |
| `CHANNEL_FILE_ATTACHED` | User selects file | file_name, file_size, file_type, channel | File input handler |
| `CHANNEL_EMOJI_PICKER_OPENED` | User opens emoji picker | channel | Emoji button handler |
| `CHANNEL_EMOJI_ADDED` | User selects emoji | emoji, channel | Emoji item handler |
| `CHANNEL_MENTION_TRIGGERED` | User clicks mention button | channel | Mention button handler |
| `CHANNEL_HASHTAG_TRIGGERED` | User clicks hashtag button | channel | Hashtag button handler |
| `CHANNEL_SWITCHED` | User switches channel | channel, channel_type | switchChannel() |
| `CHANNEL_CREATED` | User creates new channel | channel, display_name | New channel handler |

## Database Storage

### Audit Log Table Schema
```sql
CREATE TABLE audit_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  action VARCHAR(50) NOT NULL,
  entity VARCHAR(50),
  metadata_json JSON,
  ip VARCHAR(45),
  user_agent VARCHAR(500),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user_id (user_id),
  KEY idx_action (action),
  KEY idx_created_at (created_at)
);
```

### Sample Metadata JSON
```json
{
  "channel": "local:general",
  "message_length": 125,
  "tags": ["meeting", "urgent"],
  "mentions": ["john", "sarah"],
  "has_file": true,
  "file_name": "report.pdf",
  "file_size": 2048576,
  "timestamp": "2025-01-12T16:24:30.000Z",
  "channel_type": "group"
}
```

## API Endpoints

### Retrieve Audit Log
```bash
GET /api/audit?limit=50
Authorization: Bearer <JWT_TOKEN>

Response:
{
  "ok": true,
  "count": 50,
  "audit": [
    {
      "action": "CHANNEL_MESSAGE_SENT",
      "entity": "channel",
      "metadata": {...},
      "created_at": "2025-01-12 16:24:30",
      "ip": "192.168.1.1"
    },
    ...
  ]
}
```

### View Audit Log UI
- **URL**: `/audit.php`
- **Authentication**: Session-based (logged-in users only)
- **Display**: Shows 200 most recent audit events in table format
- **Columns**: Timestamp, Action, Entity, Metadata, IP Address

## Implementation Details

### Server-Side Implementation (db.php)
```php
function jarvis_audit($userId, $action, $entity, $metadata) {
  // Logs audit event with automatic IP, user_agent, and timestamp
  // Called from 50+ locations throughout codebase
  // Stores metadata as JSON for complex data structures
}

function jarvis_latest_audit($userId, $limit) {
  // Retrieves most recent audit events for user
  // Used by /api/audit endpoint and audit.php page
}
```

### Client-Side Implementation (public/channel.php)
```javascript
if (window.jarvisApi && window.jarvisApi.auditLog) {
  window.jarvisApi.auditLog('ACTION_NAME', 'channel', {
    channel: currentChannel,
    timestamp: new Date().toISOString(),
    // Additional metadata...
  });
}
```

## Audit Trail Access

### For End Users
- **UI Access**: `/audit.php` - Complete audit history with all actions
- **API Access**: `/api/audit?limit=200` - JSON response for programmatic access
- **Filtering**: Events grouped by timestamp, searchable by action name

### For Administrators
- **Database Query**: Direct SQL access to `audit_log` table
- **Compliance Reports**: Export all audit logs for user compliance
- **Security Analysis**: Track suspicious patterns or unauthorized access attempts

## Timestamp Format

All timestamps are stored in two formats:
- **Database**: MySQL TIMESTAMP format (auto-populated by `NOW()`)
- **Metadata**: ISO 8601 format (JavaScript `new Date().toISOString()`)
- **Display**: Formatted human-readable (e.g., "Jan 12, 2025 4:24 PM")

## Security & Privacy

- ✅ **User Isolation**: Each user only sees their own audit logs
- ✅ **IP Tracking**: All events logged with client IP address
- ✅ **User Agent**: Browser/device information captured
- ✅ **Immutable**: Audit entries never modified, only inserted
- ✅ **Automatic Timestamps**: Cannot be spoofed by client
- ✅ **Metadata Sanitization**: JSON parsing prevents injection

## Testing Checklist

- [ ] Send a message and verify CHANNEL_MESSAGE_SENT appears in audit log
- [ ] Delete a message and verify CHANNEL_MESSAGE_DELETED_BY_USER appears
- [ ] Click reply button and verify CHANNEL_REPLY_INITIATED appears
- [ ] Click hashtag and verify CHANNEL_HASHTAG_CLICKED appears with correct hashtag
- [ ] Attach file and verify CHANNEL_FILE_ATTACHED appears with file metadata
- [ ] Open emoji picker and verify CHANNEL_EMOJI_PICKER_OPENED appears
- [ ] Select emoji and verify CHANNEL_EMOJI_ADDED appears
- [ ] Click mention button and verify CHANNEL_MENTION_TRIGGERED appears
- [ ] Click hashtag button and verify CHANNEL_HASHTAG_TRIGGERED appears
- [ ] Switch channels and verify CHANNEL_SWITCHED appears with channel type
- [ ] Create new channel and verify CHANNEL_CREATED appears with display name
- [ ] Access `/api/audit` and verify JSON response contains all events
- [ ] Access `/audit.php` and verify table displays all events
- [ ] Verify metadata JSON contains correct values
- [ ] Check IP address is correctly captured
- [ ] Verify user_agent browser information is recorded

## Metadata Reference

### Message-Related Events
- `CHANNEL_MESSAGE_SENT`: message_length, tags[], mentions[], has_file, file_name, file_size
- `CHANNEL_MESSAGE_DELETED_BY_USER`: message_id
- `CHANNEL_REPLY_INITIATED`: recipient
- `CHANNEL_REACTION_INITIATED`: message_id

### File Events
- `CHANNEL_FILE_ATTACHED`: file_name, file_size, file_type

### Emoji Events
- `CHANNEL_EMOJI_PICKER_OPENED`: (minimal - just event trigger)
- `CHANNEL_EMOJI_ADDED`: emoji

### Channel Events
- `CHANNEL_SWITCHED`: channel, channel_type (group|direct_message)
- `CHANNEL_CREATED`: channel, display_name
- `CHANNEL_HASHTAG_CLICKED`: hashtag
- `CHANNEL_MENTION_TRIGGERED`: (minimal - just event trigger)
- `CHANNEL_HASHTAG_TRIGGERED`: (minimal - just event trigger)

## Performance Considerations

- **Audit Table Size**: With 200 events/user/day, expect ~1.5MB/user/year
- **Query Optimization**: Indexes on user_id, action, created_at
- **Retention**: Consider archiving audit logs older than 1-2 years
- **Cleanup**: `DELETE FROM audit_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 2 YEAR);`

## Future Enhancements

- [ ] Audit log export to CSV
- [ ] Advanced filtering by date range, action type, entity
- [ ] Real-time audit log dashboard
- [ ] Alert system for suspicious audit patterns
- [ ] Compliance report generation (SOC 2, GDPR)
- [ ] Audit log replication for disaster recovery
