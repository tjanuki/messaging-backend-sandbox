<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Messaging App</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <script src="https://js.pusher.com/8.2.0/pusher.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/laravel-echo@1.15.0/dist/echo.iife.js"></script>
</head>
<body class="bg-gray-100 h-screen flex flex-col" x-data="messagingApp()" x-init="initializeApp()">
    <div id="app" class="flex-1 flex flex-col">
        <!-- Header -->
        <header class="bg-blue-600 text-white p-4 shadow">
            <div class="flex justify-between items-center">
                <h1 class="text-xl font-bold">Real-time Messaging</h1>
                <div class="flex items-center space-x-4">
                    <button @click="toggleNotifications()" class="bg-blue-500 hover:bg-blue-400 px-3 py-1 rounded text-sm">
                        Enable Notifications
                    </button>
                    <span :class="connectionStatus.online ? 'bg-green-500' : 'bg-red-500'" class="text-sm px-2 py-1 rounded" x-text="connectionStatus.text"></span>
                    <span class="text-sm" x-text="currentUser.name"></span>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <div class="flex-1 flex overflow-hidden">
            <!-- Conversations List -->
            <div class="w-1/3 bg-white border-r border-gray-200 flex flex-col">
                <div class="p-4 border-b border-gray-200">
                    <h2 class="font-semibold text-gray-800">Conversations</h2>
                    <button @click="createConversation()" class="mt-2 bg-blue-500 text-white px-3 py-1 rounded text-sm hover:bg-blue-600">
                        New Conversation
                    </button>
                </div>
                <div class="flex-1 overflow-y-auto">
                    <template x-for="conversation in conversations" :key="conversation.id">
                        <div @click="selectConversation(conversation)" 
                             :class="selectedConversation?.id === conversation.id ? 'bg-blue-50 border-l-4 border-l-blue-500' : ''"
                             class="p-4 border-b border-gray-100 hover:bg-gray-50 cursor-pointer">
                            <div class="flex justify-between items-start">
                                <div class="flex-1">
                                    <h3 class="font-medium text-gray-900" x-text="conversation.name || 'Direct Message'"></h3>
                                    <p class="text-sm text-gray-500 mt-1 truncate" x-text="conversation.last_message?.content || 'No messages yet'"></p>
                                </div>
                                <span class="text-xs text-gray-400" x-text="formatTime(conversation.last_message_at)"></span>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Messages Area -->
            <div class="flex-1 flex flex-col">
                <!-- Conversation Header -->
                <div x-show="selectedConversation" class="p-4 border-b border-gray-200 bg-white">
                    <h3 class="font-semibold text-gray-800" x-text="selectedConversation?.name || 'Direct Message'"></h3>
                    <div class="text-sm text-gray-500 mt-1" x-text="'Online: ' + onlineUsers.join(', ')"></div>
                    <div x-show="typingUsers.length > 0" class="text-sm text-gray-500 italic" x-text="typingUsers.join(', ') + (typingUsers.length > 1 ? ' are' : ' is') + ' typing...'"></div>
                </div>

                <!-- Messages Container -->
                <div class="flex-1 overflow-y-auto p-4 space-y-4" x-ref="messagesContainer">
                    <template x-if="!selectedConversation">
                        <div class="text-center text-gray-500">
                            Select a conversation to start messaging
                        </div>
                    </template>
                    <template x-if="selectedConversation">
                        <div>
                            <template x-for="message in messages" :key="message.id">
                                <div :class="message.user_id === currentUser.id ? 'justify-end' : 'justify-start'" class="flex">
                                    <div :class="message.user_id === currentUser.id ? 'bg-blue-600 text-white' : 'bg-white text-gray-900 border border-gray-200'" 
                                         class="max-w-xs lg:max-w-md px-4 py-2 rounded-lg">
                                        <template x-if="message.user_id !== currentUser.id">
                                            <p class="text-sm font-medium text-gray-500 mb-1" x-text="message.user?.name"></p>
                                        </template>
                                        <p x-text="message.content"></p>
                                        <p class="text-xs opacity-75 mt-1" x-text="formatTime(message.created_at)"></p>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>

                <!-- Message Input -->
                <div x-show="selectedConversation" class="p-4 border-t border-gray-200 bg-white">
                    <div class="flex space-x-2">
                        <input 
                            x-model="newMessage"
                            @keydown.enter="sendMessage()"
                            @input="handleTyping()"
                            type="text" 
                            placeholder="Type a message..." 
                            class="flex-1 border border-gray-300 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                        >
                        <button 
                            @click="sendMessage()"
                            :disabled="!newMessage.trim()"
                            class="bg-blue-600 hover:bg-blue-700 disabled:bg-gray-400 text-white px-6 py-2 rounded-lg font-medium"
                        >
                            Send
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script>
        function messagingApp() {
            return {
                // Data
                currentUser: {
                    id: 1,
                    name: 'Test User',
                    email: 'test@example.com'
                },
                selectedConversation: null,
                conversations: [
                    {
                        id: 1,
                        name: 'General Chat',
                        type: 'group',
                        last_message: { content: 'Hello everyone!', created_at: new Date().toISOString() },
                        last_message_at: new Date().toISOString()
                    },
                    {
                        id: 2,
                        name: 'Direct Message',
                        type: 'direct',
                        last_message: { content: 'How are you?', created_at: new Date().toISOString() },
                        last_message_at: new Date().toISOString()
                    }
                ],
                messages: [],
                newMessage: '',
                onlineUsers: ['User 1', 'User 2', 'User 3'],
                typingUsers: [],
                connectionStatus: { online: false, text: 'Connecting...' },
                echo: null,
                typingTimeout: null,
                isTyping: false,
                apiToken: localStorage.getItem('api_token') || 'test-token',
                csrfToken: document.querySelector('meta[name="csrf-token"]').getAttribute('content'),

                // Initialize app
                initializeApp() {
                    this.setupWebSocket();
                    this.startHeartbeat();
                    this.initializeFlutterBridge();
                },

                // WebSocket setup
                setupWebSocket() {
                    this.echo = new Echo({
                        broadcaster: 'pusher',
                        key: 'local-key',
                        cluster: 'mt1',
                        wsHost: '127.0.0.1',
                        wsPort: 8080,
                        wssPort: 8080,
                        forceTLS: false,
                        encrypted: false,
                        disableStats: true,
                        enabledTransports: ['ws', 'wss']
                    });

                    // Connection status listeners
                    this.echo.connector.pusher.connection.bind('connected', () => {
                        this.connectionStatus = { online: true, text: 'Online' };
                        console.log('WebSocket connected');
                    });

                    this.echo.connector.pusher.connection.bind('disconnected', () => {
                        this.connectionStatus = { online: false, text: 'Offline' };
                        console.log('WebSocket disconnected');
                    });
                },

                // Flutter bridge integration
                initializeFlutterBridge() {
                    // Request device token from Flutter app
                    if (window.FlutterBridge) {
                        window.FlutterBridge.postMessage(JSON.stringify({
                            type: 'request_device_token'
                        }));
                    }

                    // Set up Flutter bridge callbacks
                    window.onDeviceTokenReceived = (token) => {
                        if (token) {
                            this.updateFCMToken(token);
                        }
                    };

                    window.onFlutterError = (error) => {
                        console.error('Flutter error:', error);
                    };
                },

                // Notification management
                toggleNotifications() {
                    if ('Notification' in window) {
                        Notification.requestPermission().then((permission) => {
                            if (permission === 'granted') {
                                console.log('Notifications enabled');
                                if (window.FlutterBridge) {
                                    window.FlutterBridge.postMessage(JSON.stringify({
                                        type: 'notification_permission',
                                        permission: permission
                                    }));
                                }
                            }
                        });
                    }
                },

                // Update FCM token
                updateFCMToken(token) {
                    fetch('/api/fcm-token', {
                        method: 'POST',
                        headers: {
                            'Authorization': 'Bearer ' + this.apiToken,
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': this.csrfToken
                        },
                        body: JSON.stringify({ fcm_token: token })
                    }).catch(error => {
                        console.error('Error updating FCM token:', error);
                    });
                },

                // Conversation management
                selectConversation(conversation) {
                    if (this.selectedConversation?.id === conversation.id) return;

                    this.selectedConversation = conversation;
                    this.loadMessages(conversation.id);
                    this.subscribeToConversation(conversation.id);
                    this.markAsRead(conversation.id);
                },

                createConversation() {
                    const name = prompt('Enter conversation name:');
                    if (name) {
                        // In real app, call API to create conversation
                        const newConversation = {
                            id: Date.now(),
                            name: name,
                            type: 'group',
                            last_message: null,
                            last_message_at: new Date().toISOString()
                        };
                        this.conversations.unshift(newConversation);
                    }
                },

                // Message management
                loadMessages(conversationId) {
                    // Mock message data for demonstration
                    this.messages = [
                        {
                            id: 1,
                            user_id: 2,
                            content: 'Hello! How are you?',
                            created_at: new Date(Date.now() - 300000).toISOString(),
                            user: { id: 2, name: 'Other User', avatar: null }
                        },
                        {
                            id: 2,
                            user_id: this.currentUser.id,
                            content: 'I\'m doing great, thanks!',
                            created_at: new Date(Date.now() - 180000).toISOString(),
                            user: this.currentUser
                        },
                        {
                            id: 3,
                            user_id: 2,
                            content: 'That\'s wonderful to hear!',
                            created_at: new Date(Date.now() - 60000).toISOString(),
                            user: { id: 2, name: 'Other User', avatar: null }
                        }
                    ];
                    
                    this.$nextTick(() => {
                        this.scrollToBottom();
                    });
                },

                sendMessage() {
                    if (!this.newMessage.trim() || !this.selectedConversation) return;

                    const content = this.newMessage.trim();
                    this.stopTyping();

                    // Create message object
                    const message = {
                        id: Date.now(),
                        user_id: this.currentUser.id,
                        content: content,
                        created_at: new Date().toISOString(),
                        user: this.currentUser
                    };

                    // Add to messages immediately
                    this.messages.push(message);
                    this.newMessage = '';
                    
                    this.$nextTick(() => {
                        this.scrollToBottom();
                    });

                    // In real app, send to API
                    this.sendMessageToAPI(this.selectedConversation.id, content);
                },

                sendMessageToAPI(conversationId, content) {
                    fetch(`/api/conversations/${conversationId}/messages`, {
                        method: 'POST',
                        headers: {
                            'Authorization': 'Bearer ' + this.apiToken,
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': this.csrfToken
                        },
                        body: JSON.stringify({
                            content: content,
                            type: 'text'
                        })
                    }).catch(error => {
                        console.error('Error sending message:', error);
                    });
                },

                markAsRead(conversationId) {
                    fetch(`/api/conversations/${conversationId}/read`, {
                        method: 'POST',
                        headers: {
                            'Authorization': 'Bearer ' + this.apiToken,
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': this.csrfToken
                        }
                    }).catch(error => {
                        console.error('Error marking as read:', error);
                    });
                },

                // Typing indicators
                handleTyping() {
                    if (!this.selectedConversation) return;

                    if (!this.isTyping) {
                        this.startTyping();
                    }

                    clearTimeout(this.typingTimeout);
                    this.typingTimeout = setTimeout(() => {
                        this.stopTyping();
                    }, 3000);
                },

                startTyping() {
                    if (this.isTyping || !this.selectedConversation) return;
                    
                    this.isTyping = true;
                    // In real app, send typing start to API
                    console.log('Started typing in conversation', this.selectedConversation.id);
                },

                stopTyping() {
                    if (!this.isTyping) return;
                    
                    this.isTyping = false;
                    clearTimeout(this.typingTimeout);
                    // In real app, send typing stop to API
                    console.log('Stopped typing in conversation', this.selectedConversation?.id);
                },

                // WebSocket event subscriptions
                subscribeToConversation(conversationId) {
                    if (!this.echo) return;

                    this.echo.private(`conversation.${conversationId}`)
                        .listen('message.sent', (e) => {
                            if (e.message.user_id !== this.currentUser.id) {
                                this.messages.push(e.message);
                                this.$nextTick(() => {
                                    this.scrollToBottom();
                                });
                                
                                // Send notification to Flutter
                                this.sendNotificationToFlutter(e.user.name, e.message.content, {
                                    conversation_id: conversationId,
                                    message_id: e.message.id
                                });
                            }
                        })
                        .listen('user.typing.start', (e) => {
                            if (e.user.id !== this.currentUser.id && !this.typingUsers.includes(e.user.name)) {
                                this.typingUsers.push(e.user.name);
                            }
                        })
                        .listen('user.typing.stop', (e) => {
                            this.typingUsers = this.typingUsers.filter(name => name !== e.user.name);
                        })
                        .listen('user.online', (e) => {
                            if (!this.onlineUsers.includes(e.user.name)) {
                                this.onlineUsers.push(e.user.name);
                            }
                        })
                        .listen('user.offline', (e) => {
                            this.onlineUsers = this.onlineUsers.filter(name => name !== e.user.name);
                        });
                },

                // Utility functions
                sendNotificationToFlutter(title, body, data) {
                    if (window.FlutterBridge) {
                        window.FlutterBridge.postMessage(JSON.stringify({
                            type: 'notification',
                            title: title,
                            body: body,
                            data: data
                        }));
                    }
                },

                scrollToBottom() {
                    if (this.$refs.messagesContainer) {
                        this.$refs.messagesContainer.scrollTop = this.$refs.messagesContainer.scrollHeight;
                    }
                },

                formatTime(timestamp) {
                    const date = new Date(timestamp);
                    const now = new Date();
                    const diffInHours = (now - date) / (1000 * 60 * 60);
                    
                    if (diffInHours < 24) {
                        return date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
                    } else {
                        return date.toLocaleDateString();
                    }
                },

                startHeartbeat() {
                    setInterval(() => {
                        // Send heartbeat to maintain online status
                        fetch('/api/user/heartbeat', {
                            method: 'POST',
                            headers: {
                                'Authorization': 'Bearer ' + this.apiToken,
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': this.csrfToken
                            }
                        }).catch(error => {
                            console.error('Heartbeat failed:', error);
                        });
                    }, 30000);
                }
            }
        }
    </script>
</body>
</html>