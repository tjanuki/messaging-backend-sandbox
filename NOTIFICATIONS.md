# Push Notifications System

This document describes the push notification implementation for the Laravel messaging application using Firebase Cloud Messaging (FCM).

## Overview

The notification system is designed to send push notifications to offline users when new messages are received. It uses Firebase Cloud Messaging (FCM) for reliable message delivery across different platforms.

## Architecture

### Components

1. **NotificationService** - Core service for sending notifications
2. **SendPushNotificationJob** - Queue job for async notification delivery
3. **SendBulkNotificationJob** - Queue job for bulk notifications
4. **SendOfflineNotificationListener** - Event listener for automatic notifications
5. **NotificationController** - API endpoints for notification management
6. **UserObserver** - Monitors user changes including FCM tokens

### Flow

1. User sends a message via MessageController
2. MessageService creates the message and broadcasts MessageSent event
3. SendOfflineNotificationListener catches the event
4. Listener dispatches SendPushNotificationJob to queue
5. Job executes and sends notifications to offline participants
6. Invalid tokens are automatically cleaned up

## Configuration

### Environment Variables

Add these to your `.env` file:

```env
FCM_SERVER_KEY=your-firebase-server-key-here
```

### Queue Configuration

The notification system uses the `notifications` queue. Make sure to process this queue:

```bash
php artisan queue:work --queue=notifications
```

## API Endpoints

### Authentication Endpoints

- `POST /api/fcm-token` - Update user's FCM token

### Notification Management

- `POST /api/notifications/subscribe` - Subscribe to topic notifications
- `POST /api/notifications/unsubscribe` - Unsubscribe from topic notifications
- `POST /api/notifications/test` - Send test notification
- `POST /api/notifications/topic` - Send topic notification
- `GET /api/notifications/preferences` - Get notification preferences
- `PUT /api/notifications/preferences` - Update notification preferences
- `POST /api/notifications/validate-token` - Validate FCM token

## Usage Examples

### Update FCM Token

```javascript
fetch('/api/fcm-token', {
    method: 'POST',
    headers: {
        'Authorization': 'Bearer ' + token,
        'Content-Type': 'application/json'
    },
    body: JSON.stringify({
        fcm_token: 'your-fcm-token-here'
    })
});
```

### Send Test Notification

```javascript
fetch('/api/notifications/test', {
    method: 'POST',
    headers: {
        'Authorization': 'Bearer ' + token,
        'Content-Type': 'application/json'
    },
    body: JSON.stringify({
        title: 'Test Notification',
        body: 'This is a test message',
        data: {
            custom_key: 'custom_value'
        }
    })
});
```

### Subscribe to Topic

```javascript
fetch('/api/notifications/subscribe', {
    method: 'POST',
    headers: {
        'Authorization': 'Bearer ' + token,
        'Content-Type': 'application/json'
    },
    body: JSON.stringify({
        topic: 'general-announcements'
    })
});
```

## Notification Types

### Message Notifications

Automatically sent when:
- A new message is received in a conversation
- The recipient is offline (not actively connected via WebSocket)
- The recipient has a valid FCM token

### Topic Notifications

Manually sent to groups of users subscribed to specific topics:
- General announcements
- System maintenance notifications
- Feature updates

## Error Handling

### Invalid Tokens

- Automatically detected during notification delivery
- Invalid tokens are removed from user records
- Failed deliveries are logged for monitoring

### Retry Logic

- Failed notifications are retried up to 3 times
- Exponential backoff with 60-second base delay
- Jobs expire after 10 minutes

## Testing

### Command Line Testing

```bash
# Send test notification to specific user
php artisan notification:test 1 --title="Hello" --body="Test message"
```

### Monitoring

Check logs for notification delivery status:

```bash
tail -f storage/logs/laravel.log | grep "FCM"
```

## Best Practices

### Token Management

1. Update FCM tokens when app starts
2. Handle token refresh in mobile app
3. Remove tokens on user logout
4. Clean up invalid tokens regularly

### Performance

1. Use queues for all notification sending
2. Batch notifications when possible
3. Set appropriate queue delays
4. Monitor queue length and processing time

### Security

1. Validate all notification content
2. Sanitize user-generated content
3. Rate limit notification endpoints
4. Log all notification activities

## Troubleshooting

### Common Issues

1. **Notifications not received**
   - Check FCM server key configuration
   - Verify user has valid FCM token
   - Check user is offline (online users get WebSocket messages)
   - Review application logs for errors

2. **High failure rate**
   - Monitor for invalid tokens
   - Check FCM service status
   - Verify network connectivity
   - Review token refresh logic in mobile app

3. **Queue not processing**
   - Ensure queue worker is running
   - Check queue connection configuration
   - Monitor for failed jobs
   - Review queue worker logs

### Debugging

Enable detailed logging by setting log level to debug in `.env`:

```env
LOG_LEVEL=debug
```

Monitor queue jobs:

```bash
php artisan queue:monitor notifications
```

Check failed jobs:

```bash
php artisan queue:failed
```

## Integration with Flutter

### Token Registration

```dart
// Get FCM token
String? token = await FirebaseMessaging.instance.getToken();

// Send to Laravel backend
await http.post(
  Uri.parse('$baseUrl/api/fcm-token'),
  headers: {
    'Authorization': 'Bearer $authToken',
    'Content-Type': 'application/json',
  },
  body: jsonEncode({'fcm_token': token}),
);
```

### Handle Token Refresh

```dart
FirebaseMessaging.instance.onTokenRefresh.listen((newToken) {
  // Send updated token to backend
  updateFcmToken(newToken);
});
```

### Handle Notifications

```dart
FirebaseMessaging.onMessage.listen((RemoteMessage message) {
  // Handle foreground notifications
  print('Received message: ${message.notification?.title}');
});

FirebaseMessaging.onBackgroundMessage(_firebaseMessagingBackgroundHandler);
```

## Monitoring and Analytics

### Metrics to Track

- Notification delivery success rate
- Token refresh frequency
- Queue processing time
- Failed notification count
- User engagement with notifications

### Alerts

Set up alerts for:
- High notification failure rate (>10%)
- Queue backup (>100 pending jobs)
- FCM service errors
- Invalid token spike

## Future Enhancements

### Planned Features

1. **Rich Notifications**
   - Image support
   - Action buttons
   - Custom sounds

2. **Advanced Targeting**
   - User segments
   - Geolocation-based notifications
   - Time zone awareness

3. **Analytics Integration**
   - Delivery tracking
   - Click-through rates
   - User engagement metrics

4. **A/B Testing**
   - Message content testing
   - Send time optimization
   - Frequency optimization