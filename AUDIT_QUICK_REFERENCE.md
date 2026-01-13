# Channel Widget Audit Logging - Quick Reference

## For Developers

### Adding Audit Logging to New Features

```javascript
// Pattern: Check if auditLog is available, then call it
if (window.jarvisApi && window.jarvisApi.auditLog) {
  window.jarvisApi.auditLog('ACTION_NAME', 'channel', {
    // Required metadata
    channel: currentChannel,
    timestamp: new Date().toISOString(),
    
    // Optional: specific to this action
    entity_id: id,
    field_name: value,
    // ...
  });
}
```

### Audit Events Available

#### In index.php (Server-side)
```php
// Automatically logged when:
jarvis_audit($userId, 'CHANNEL_MSG', 'channel', [...]);          // Message sent
jarvis_audit($userId, 'CHANNEL_MESSAGE_DELETED', 'channel', [...]);  // Message deleted
jarvis_audit($userId, 'MENTIONED', 'channel', [...]);  // User mentioned
```

#### In public/channel.php (Client-side)
All of these are ready to use:
- `CHANNEL_MESSAGE_SENT` - User sends message
- `CHANNEL_MESSAGE_DELETED_BY_USER` - Delete button clicked
- `CHANNEL_REPLY_INITIATED` - Reply button clicked
- `CHANNEL_HASHTAG_CLICKED` - Hashtag clicked in message
- `CHANNEL_REACTION_INITIATED` - React button clicked
- `CHANNEL_FILE_ATTACHED` - File selected for upload
- `CHANNEL_EMOJI_PICKER_OPENED` - Emoji picker opened
- `CHANNEL_EMOJI_ADDED` - Emoji selected
- `CHANNEL_MENTION_TRIGGERED` - @ button clicked
- `CHANNEL_HASHTAG_TRIGGERED` - # button clicked
- `CHANNEL_SWITCHED` - Channel changed
- `CHANNEL_CREATED` - New channel created

### Metadata Best Practices

```javascript
// GOOD: Minimal but informative
{
  channel: "local:general",
  message_id: 123,
  timestamp: "2025-01-12T16:24:30.000Z"
}

// BETTER: Includes context
{
  channel: "local:general",
  channel_type: "group",
  message_id: 123,
  message_length: 145,
  has_mentions: true,
  has_file: true,
  timestamp: "2025-01-12T16:24:30.000Z"
}

// BAD: Too much data
{
  channel: "local:general",
  full_message_text: "entire message content here...",  // DON'T store full content
  user_object: {...},  // DON'T store full objects
  password: "...",  // DON'T store sensitive data
}
```

### Timestamp Handling

Always use ISO 8601 format for audit events:
```javascript
// CORRECT
timestamp: new Date().toISOString()  // Result: "2025-01-12T16:24:30.123Z"

// WRONG
timestamp: new Date()  // Returns object, not string
timestamp: Date.now()  // Returns milliseconds since epoch
```

### Common Patterns

#### Pattern 1: Button Click
```javascript
document.getElementById('myButton').addEventListener('click', () => {
  if (window.jarvisApi && window.jarvisApi.auditLog) {
    window.jarvisApi.auditLog('MY_ACTION', 'channel', {
      channel: currentChannel,
      timestamp: new Date().toISOString()
    });
  }
});
```

#### Pattern 2: Form Submission
```javascript
form.addEventListener('submit', async (e) => {
  e.preventDefault();
  
  // Audit before sending
  if (window.jarvisApi && window.jarvisApi.auditLog) {
    window.jarvisApi.auditLog('FORM_SUBMITTED', 'channel', {
      channel: currentChannel,
      action: 'create',
      timestamp: new Date().toISOString()
    });
  }
  
  // Then send form data
  await fetch('/api/endpoint', { ... });
});
```

#### Pattern 3: State Change
```javascript
async function changeState(newState) {
  const oldState = currentState;
  currentState = newState;
  
  // Log the change
  if (window.jarvisApi && window.jarvisApi.auditLog) {
    window.jarvisApi.auditLog('STATE_CHANGED', 'channel', {
      channel: currentChannel,
      old_state: oldState,
      new_state: newState,
      timestamp: new Date().toISOString()
    });
  }
}
```

### Querying Audit Logs

#### Via JavaScript/Fetch
```javascript
// Get recent audit events
const response = await fetch('/api/audit?limit=50', {
  headers: { 'Authorization': 'Bearer ' + window.jarvisJwt }
});
const data = await response.json();
console.log(data.audit);  // Array of audit events
```

#### Via cURL
```bash
curl -X GET 'http://localhost:8000/api/audit?limit=20' \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

#### Via PHP
```php
$events = jarvis_latest_audit($userId, 50);
foreach ($events as $event) {
  echo $event['action'];  // e.g., "CHANNEL_MESSAGE_SENT"
  $meta = json_decode($event['metadata'], true);
  echo $meta['channel'];  // e.g., "local:general"
}
```

### Audit Log Page

Access at: `http://your-domain/audit.php`
- Shows 200 most recent events
- Displays all CHANNEL_* events automatically
- Shows timestamp, action, entity, metadata
- Shows IP address and user agent

### Debugging

#### Check if auditLog is available
```javascript
console.log(window.jarvisApi);  // Should show auditLog method
console.log(typeof window.jarvisApi?.auditLog);  // Should be 'function'
```

#### Check audit log in browser console
```javascript
// After performing an action, check console for any errors
// Audit logs should appear as network requests to /api/audit
```

#### View server logs
```bash
# Watch PHP error log
tail -f storage/php_errors.log

# Check database directly
mysql> SELECT * FROM audit_log WHERE user_id = 47 ORDER BY id DESC LIMIT 10;
```

### Performance Tips

- ✅ Audit calls are asynchronous (non-blocking)
- ✅ Use meaningful action names (searchable)
- ✅ Include channel context when relevant
- ✅ Add timestamp to all events
- ✅ Don't store large objects in metadata
- ✅ Don't store sensitive information
- ✅ Limit queries with LIMIT clause
- ✅ Archive old audit logs annually

### Security Considerations

- ✅ User ID is auto-set from JWT (can't be spoofed)
- ✅ IP address is auto-captured (can't be spoofed)
- ✅ Timestamps are server-set for created_at
- ✅ Audit records are immutable (INSERT-only)
- ✅ Users can only see their own logs
- ✅ Don't log passwords or sensitive data
- ✅ Don't log full message content (log metadata about it instead)
- ✅ JWT validation happens automatically

### Event Naming Convention

Use pattern: `{SCOPE}_{ACTION}`
- Scope: CHANNEL, VOICE, PHOTO, DEVICE, etc.
- Action: MESSAGE_SENT, FILE_ATTACHED, SWITCHED, etc.

Examples:
- ✅ `CHANNEL_MESSAGE_SENT` (good)
- ✅ `CHANNEL_FILE_ATTACHED` (good)
- ❌ `MessageSent` (bad - not all caps)
- ❌ `MSG_SENT` (bad - unclear scope)

### Metadata Field Naming

Use snake_case for consistency:
```javascript
// GOOD
{
  message_id: 123,
  file_name: "doc.pdf",
  file_size: 2048576,
  channel_type: "group"
}

// BAD
{
  messageId: 123,  // camelCase
  fileName: "doc.pdf",  // camelCase
  filesize: 2048576,  // unclear naming
  type: "group"  // too generic
}
```

### Common Mistakes

❌ **Mistake 1**: Forgetting to check if window.jarvisApi exists
```javascript
// BAD - Will crash if auditLog not available
window.jarvisApi.auditLog(...);

// GOOD - Check first
if (window.jarvisApi && window.jarvisApi.auditLog) {
  window.jarvisApi.auditLog(...);
}
```

❌ **Mistake 2**: Using new Date() instead of toISOString()
```javascript
// BAD - Not a string
{ timestamp: new Date() }

// GOOD - ISO string
{ timestamp: new Date().toISOString() }
```

❌ **Mistake 3**: Storing too much data
```javascript
// BAD - Large object
{ full_message: messageObject }

// GOOD - Just what's needed
{ message_length: messageObject.length }
```

❌ **Mistake 4**: Missing required fields
```javascript
// BAD - No channel or timestamp
{ action: 'sent' }

// GOOD - Has context
{ channel: currentChannel, timestamp: ... }
```

### Support Resources

- **Documentation**: See AUDIT_LOG_INTEGRATION.md
- **API Reference**: Check index.php:/api/audit
- **Database Schema**: Run: `DESCRIBE audit_log;`
- **UI Reference**: Open /audit.php in browser
- **Code Examples**: See public/channel.php lines 880-1204

### Testing New Audit Event

1. Add audit logging call in code
2. Trigger the action in UI
3. Check browser Network tab for POST to /api/audit
4. Open /audit.php and look for new event
5. Verify metadata is complete
6. Verify timestamp is correct

### Deployment

Before deploying to production:
- [ ] All new audit calls use correct pattern
- [ ] window.jarvisApi check included
- [ ] Meaningful action names
- [ ] Metadata is relevant and complete
- [ ] No sensitive data in metadata
- [ ] Timestamps in ISO format
- [ ] Error handling in place
- [ ] Tested in dev environment
- [ ] No console errors on deploy

---

**Last Updated**: 2025-01-12
**Status**: ✅ Complete and Ready for Use
