<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Enhanced Messaging App</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <script src="https://js.pusher.com/8.2.0/pusher.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/laravel-echo@1.15.0/dist/echo.iife.js"></script>
    <style>
        /* Custom scrollbar */
        .custom-scrollbar::-webkit-scrollbar {
            width: 6px;
        }
        .custom-scrollbar::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 10px;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }
        
        /* Message animations */
        .message-enter {
            animation: slideInUp 0.3s ease-out;
        }
        
        @keyframes slideInUp {
            from {
                transform: translateY(20px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        /* Typing indicator animation */
        .typing-dots {
            display: inline-block;
        }
        
        .typing-dots span {
            display: inline-block;
            width: 4px;
            height: 4px;
            border-radius: 50%;
            background-color: #9ca3af;
            margin: 0 1px;
            animation: typing 1.4s infinite;
        }
        
        .typing-dots span:nth-child(2) {
            animation-delay: 0.2s;
        }
        
        .typing-dots span:nth-child(3) {
            animation-delay: 0.4s;
        }
        
        @keyframes typing {
            0%, 60%, 100% {
                transform: translateY(0);
            }
            30% {
                transform: translateY(-10px);
            }
        }
    </style>
</head>
<body class="bg-gradient-to-br from-blue-50 to-indigo-100 h-screen flex flex-col" x-data="enhancedMessagingApp()" x-init="initializeApp()">
    <div id="app" class="flex-1 flex flex-col max-w-7xl mx-auto w-full">
        <!-- Enhanced Header -->
        <header class="bg-white shadow-lg border-b border-gray-200">
            <div class="px-6 py-4">
                <div class="flex justify-between items-center">
                    <div class="flex items-center space-x-4">
                        <div class="w-10 h-10 bg-gradient-to-r from-blue-500 to-purple-600 rounded-full flex items-center justify-center">
                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
                            </svg>
                        </div>
                        <div>
                            <h1 class="text-2xl font-bold text-gray-800">Messages</h1>
                            <p class="text-sm text-gray-500">Real-time messaging</p>
                        </div>
                    </div>
                    <div class="flex items-center space-x-4">
                        <!-- Notification toggle -->
                        <button @click="toggleNotifications()" 
                                :class="notificationsEnabled ? 'bg-green-500 hover:bg-green-600' : 'bg-gray-500 hover:bg-gray-600'"
                                class="px-4 py-2 rounded-lg text-white text-sm font-medium transition-colors duration-200">
                            <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-2.586-2.586a2 2 0 00-2.828 0L12 17h3zm-3-10a3 3 0 11-6 0 3 3 0 016 0z"></path>
                            </svg>
                            <span x-text="notificationsEnabled ? 'Notifications On' : 'Enable Notifications'"></span>
                        </button>
                        
                        <!-- Connection status -->
                        <div class="flex items-center space-x-2">
                            <div :class="connectionStatus.online ? 'bg-green-500' : 'bg-red-500'" 
                                 class="w-3 h-3 rounded-full animate-pulse"></div>
                            <span class="text-sm font-medium text-gray-700" x-text="connectionStatus.text"></span>
                        </div>
                        
                        <!-- User info -->
                        <div class="flex items-center space-x-2">
                            <div class="w-8 h-8 bg-gradient-to-r from-purple-500 to-pink-500 rounded-full flex items-center justify-center">
                                <span class="text-white font-semibold text-sm" x-text="currentUser.name.charAt(0)"></span>
                            </div>
                            <span class="text-sm font-medium text-gray-700" x-text="currentUser.name"></span>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <div class="flex-1 flex overflow-hidden bg-white rounded-lg shadow-lg mx-4 my-4">
            <!-- Enhanced Conversations List -->
            <div class="w-1/3 border-r border-gray-200 flex flex-col bg-gray-50">
                <div class="p-6 border-b border-gray-200 bg-white">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-lg font-semibold text-gray-800">Conversations</h2>
                        <span class="bg-blue-100 text-blue-800 text-xs font-medium px-2.5 py-0.5 rounded-full" 
                              x-text="conversations.length + ' chats'"></span>
                    </div>
                    <button @click="createConversation()" 
                            class="w-full bg-gradient-to-r from-blue-500 to-purple-600 hover:from-blue-600 hover:to-purple-700 text-white px-4 py-2 rounded-lg font-medium transition-all duration-200 shadow-md hover:shadow-lg">
                        <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                        </svg>
                        New Conversation
                    </button>
                </div>
                <div class="flex-1 overflow-y-auto custom-scrollbar">
                    <template x-for="conversation in conversations" :key="conversation.id">
                        <div @click="selectConversation(conversation)" 
                             :class="selectedConversation?.id === conversation.id ? 'bg-blue-50 border-r-4 border-r-blue-500' : 'hover:bg-gray-100'"
                             class="p-4 border-b border-gray-100 cursor-pointer transition-all duration-200">
                            <div class="flex items-start space-x-3">
                                <!-- Conversation avatar -->
                                <div class="w-12 h-12 bg-gradient-to-r from-indigo-500 to-purple-600 rounded-full flex items-center justify-center flex-shrink-0">
                                    <span class="text-white font-semibold" x-text="(conversation.name || 'DM').charAt(0)"></span>
                                </div>
                                
                                <div class="flex-1 min-w-0">
                                    <div class="flex justify-between items-start">
                                        <h3 class="font-semibold text-gray-900 truncate" x-text="conversation.name || 'Direct Message'"></h3>
                                        <span class="text-xs text-gray-500 flex-shrink-0 ml-2" x-text="formatTime(conversation.last_message_at)"></span>
                                    </div>
                                    <p class="text-sm text-gray-600 mt-1 truncate" x-text="conversation.last_message?.content || 'No messages yet'"></p>
                                    <div class="flex justify-between items-center mt-2">
                                        <span :class=\"conversation.type === 'group' ? 'bg-green-100 text-green-800' : 'bg-blue-100 text-blue-800'\" 
                                              class="text-xs font-medium px-2 py-1 rounded-full" 
                                              x-text="conversation.type === 'group' ? 'Group' : 'Direct'"></span>
                                        <template x-if="conversation.unread_count && conversation.unread_count > 0">
                                            <span class="bg-red-500 text-white text-xs font-bold px-2 py-1 rounded-full" x-text="conversation.unread_count"></span>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Enhanced Messages Area -->
            <div class="flex-1 flex flex-col">
                <!-- Conversation Header -->
                <div x-show="selectedConversation" class="p-6 border-b border-gray-200 bg-white">
                    <div class="flex justify-between items-center">
                        <div class="flex items-center space-x-3">
                            <div class="w-10 h-10 bg-gradient-to-r from-indigo-500 to-purple-600 rounded-full flex items-center justify-center">
                                <span class="text-white font-semibold" x-text="(selectedConversation?.name || 'DM').charAt(0)"></span>
                            </div>
                            <div>
                                <h3 class="font-semibold text-gray-800" x-text="selectedConversation?.name || 'Direct Message'"></h3>
                                <div class="flex items-center space-x-4 text-sm text-gray-500">
                                    <span x-text="'Online: ' + onlineUsers.length + ' users'"></span>
                                    <template x-if="typingUsers.length > 0">
                                        <div class="flex items-center space-x-1">
                                            <div class="typing-dots">
                                                <span></span>
                                                <span></span>
                                                <span></span>
                                            </div>
                                            <span x-text="typingUsers.join(', ') + (typingUsers.length > 1 ? ' are' : ' is') + ' typing'"></span>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Conversation actions -->
                        <div class="flex items-center space-x-2">
                            <button class="p-2 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-lg transition-colors">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                </svg>
                            </button>
                            <button class="p-2 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-lg transition-colors">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z"></path>
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Enhanced Messages Container -->
                <div class="flex-1 overflow-y-auto p-6 space-y-4 custom-scrollbar bg-gray-50" x-ref="messagesContainer">
                    <template x-if="!selectedConversation">
                        <div class="flex items-center justify-center h-full">
                            <div class="text-center">
                                <div class="w-24 h-24 bg-gray-200 rounded-full flex items-center justify-center mx-auto mb-4">
                                    <svg class="w-12 h-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
                                    </svg>
                                </div>
                                <h3 class="text-lg font-medium text-gray-900 mb-2">Select a conversation</h3>
                                <p class="text-gray-500">Choose a conversation from the list to start messaging</p>
                            </div>
                        </div>
                    </template>
                    
                    <template x-if="selectedConversation">
                        <div>
                            <template x-for="(message, index) in messages" :key="message.id">
                                <div :class="message.user_id === currentUser.id ? 'justify-end' : 'justify-start'" 
                                     class="flex message-enter">
                                    <div class="flex items-end space-x-2 max-w-xs lg:max-w-md">
                                        <!-- Avatar for other users -->
                                        <template x-if="message.user_id !== currentUser.id">
                                            <div class="w-8 h-8 bg-gradient-to-r from-green-500 to-blue-500 rounded-full flex items-center justify-center flex-shrink-0">
                                                <span class="text-white text-xs font-semibold" x-text="message.user?.name?.charAt(0)"></span>
                                            </div>
                                        </template>
                                        
                                        <div>
                                            <!-- Message bubble with enhanced styling -->
                                            <div :class="message.user_id === currentUser.id ? 'bg-gradient-to-r from-blue-500 to-purple-600 text-white' : 'bg-white text-gray-900 border border-gray-200 shadow-sm'" 
                                                 class="px-4 py-3 rounded-2xl relative">
                                                <template x-if="message.user_id !== currentUser.id && (index === 0 || messages[index-1].user_id !== message.user_id)">
                                                    <p class="text-xs font-medium opacity-75 mb-1" x-text="message.user?.name"></p>
                                                </template>
                                                <p class="text-sm leading-relaxed" x-text="message.content"></p>
                                                <p :class="message.user_id === currentUser.id ? 'text-blue-100' : 'text-gray-500'" 
                                                   class="text-xs mt-1" x-text="formatTime(message.created_at)"></p>
                                            </div>
                                            
                                            <!-- Message status indicators -->
                                            <template x-if="message.user_id === currentUser.id">
                                                <div class="flex justify-end mt-1">
                                                    <div class="flex items-center space-x-1 text-xs text-gray-400">
                                                        <template x-if="message.status === 'sent'">
                                                            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                                                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                                            </svg>
                                                        </template>
                                                        <template x-if="message.status === 'delivered'">
                                                            <svg class="w-3 h-3 text-blue-500" fill="currentColor" viewBox="0 0 20 20">
                                                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                                            </svg>
                                                        </template>
                                                        <template x-if="message.status === 'read'">
                                                            <svg class="w-3 h-3 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                                                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                                            </svg>
                                                        </template>
                                                    </div>
                                                </div>
                                            </template>
                                        </div>
                                        
                                        <!-- Avatar for current user -->
                                        <template x-if="message.user_id === currentUser.id">
                                            <div class="w-8 h-8 bg-gradient-to-r from-purple-500 to-pink-500 rounded-full flex items-center justify-center flex-shrink-0">
                                                <span class="text-white text-xs font-semibold" x-text="currentUser.name.charAt(0)"></span>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>

                <!-- Enhanced Message Input -->
                <div x-show="selectedConversation" class="p-6 border-t border-gray-200 bg-white">
                    <div class="flex items-end space-x-4">
                        <!-- Attachment button -->
                        <button class="p-3 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-full transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"></path>
                            </svg>
                        </button>
                        
                        <!-- Message input -->
                        <div class="flex-1 relative">
                            <textarea x-model="newMessage"
                                    @keydown.enter.prevent="sendMessage()"
                                    @input="handleTyping()"
                                    rows="1"
                                    placeholder="Type a message..."
                                    class="w-full px-4 py-3 border border-gray-300 rounded-2xl focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent resize-none"
                                    style="min-height: 48px; max-height: 120px;"></textarea>
                        </div>
                        
                        <!-- Emoji button -->
                        <button class="p-3 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-full transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.828 14.828a4 4 0 01-5.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </button>
                        
                        <!-- Send button -->
                        <button @click="sendMessage()"
                                :disabled="!newMessage.trim()"
                                :class="newMessage.trim() ? 'bg-gradient-to-r from-blue-500 to-purple-600 hover:from-blue-600 hover:to-purple-700' : 'bg-gray-300'"
                                class="p-3 text-white rounded-full transition-all duration-200 shadow-md hover:shadow-lg disabled:cursor-not-allowed">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path>
                            </svg>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script>
        function enhancedMessagingApp() {
            return {
                // Data
                currentUser: {
                    id: 1,
                    name: 'John Doe',
                    email: 'john@example.com',
                    avatar: null
                },
                selectedConversation: null,
                conversations: [
                    {
                        id: 1,
                        name: 'Team Design',
                        type: 'group',
                        last_message: { content: 'Great work on the new designs! 🎨', created_at: new Date().toISOString() },
                        last_message_at: new Date().toISOString(),
                        unread_count: 2
                    },
                    {
                        id: 2,
                        name: 'Sarah Wilson',
                        type: 'direct',
                        last_message: { content: 'Are we still on for lunch tomorrow?', created_at: new Date(Date.now() - 300000).toISOString() },
                        last_message_at: new Date(Date.now() - 300000).toISOString(),
                        unread_count: 0
                    },
                    {
                        id: 3,
                        name: 'Project Alpha',
                        type: 'group',
                        last_message: { content: 'Meeting at 3 PM today', created_at: new Date(Date.now() - 3600000).toISOString() },
                        last_message_at: new Date(Date.now() - 3600000).toISOString(),
                        unread_count: 5
                    }
                ],
                messages: [],
                newMessage: '',
                onlineUsers: ['Sarah Wilson', 'Mike Johnson', 'Emily Chen'],
                typingUsers: [],
                connectionStatus: { online: false, text: 'Connecting...' },
                notificationsEnabled: false,
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
                    this.checkNotificationPermission();
                },

                // Check notification permission
                checkNotificationPermission() {
                    if ('Notification' in window) {
                        this.notificationsEnabled = Notification.permission === 'granted';
                    }
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
                        this.connectionStatus = { online: true, text: 'Connected' };
                        console.log('WebSocket connected');
                    });

                    this.echo.connector.pusher.connection.bind('disconnected', () => {
                        this.connectionStatus = { online: false, text: 'Disconnected' };
                        console.log('WebSocket disconnected');
                    });

                    this.echo.connector.pusher.connection.bind('connecting', () => {
                        this.connectionStatus = { online: false, text: 'Connecting...' };
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
                        if (this.notificationsEnabled) {
                            // Turn off notifications
                            this.notificationsEnabled = false;
                            if (window.FlutterBridge) {
                                window.FlutterBridge.postMessage(JSON.stringify({
                                    type: 'notification_permission',
                                    permission: 'denied'
                                }));
                            }
                        } else {
                            // Request permission
                            Notification.requestPermission().then((permission) => {
                                this.notificationsEnabled = permission === 'granted';
                                if (window.FlutterBridge) {
                                    window.FlutterBridge.postMessage(JSON.stringify({
                                        type: 'notification_permission',
                                        permission: permission
                                    }));
                                }
                            });
                        }
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
                    
                    // Clear unread count
                    conversation.unread_count = 0;
                },

                createConversation() {
                    const name = prompt('Enter conversation name:');
                    if (name && name.trim()) {
                        // In real app, call API to create conversation
                        const newConversation = {
                            id: Date.now(),
                            name: name.trim(),
                            type: 'group',
                            last_message: null,
                            last_message_at: new Date().toISOString(),
                            unread_count: 0
                        };
                        this.conversations.unshift(newConversation);
                    }
                },

                // Message management
                loadMessages(conversationId) {
                    // Mock message data with enhanced properties
                    this.messages = [
                        {
                            id: 1,
                            user_id: 2,
                            content: 'Hey! How are you doing today? 😊',
                            created_at: new Date(Date.now() - 300000).toISOString(),
                            user: { id: 2, name: 'Sarah Wilson', avatar: null },
                            status: 'read'
                        },
                        {
                            id: 2,
                            user_id: this.currentUser.id,
                            content: 'I\'m doing great! Just working on some new features for the app.',
                            created_at: new Date(Date.now() - 180000).toISOString(),
                            user: this.currentUser,
                            status: 'delivered'
                        },
                        {
                            id: 3,
                            user_id: 2,
                            content: 'That sounds exciting! Can\'t wait to see what you\'ve been working on. The new design looks amazing! 🚀',
                            created_at: new Date(Date.now() - 60000).toISOString(),
                            user: { id: 2, name: 'Sarah Wilson', avatar: null },
                            status: 'read'
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

                    // Create message object with enhanced properties
                    const message = {
                        id: Date.now(),
                        user_id: this.currentUser.id,
                        content: content,
                        created_at: new Date().toISOString(),
                        user: this.currentUser,
                        status: 'sent'
                    };

                    // Add to messages immediately
                    this.messages.push(message);
                    this.newMessage = '';
                    
                    // Update conversation last message
                    const conversation = this.conversations.find(c => c.id === this.selectedConversation.id);
                    if (conversation) {
                        conversation.last_message = { content: content, created_at: message.created_at };
                        conversation.last_message_at = message.created_at;
                    }
                    
                    this.$nextTick(() => {
                        this.scrollToBottom();
                    });

                    // Simulate message status updates
                    setTimeout(() => {
                        message.status = 'delivered';
                    }, 1000);
                    
                    setTimeout(() => {
                        message.status = 'read';
                    }, 3000);

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
                    if (window.FlutterBridge && this.notificationsEnabled) {
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
                    const diffInDays = diffInHours / 24;
                    
                    if (diffInHours < 1) {
                        return 'Just now';
                    } else if (diffInHours < 24) {
                        return date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
                    } else if (diffInDays < 7) {
                        return date.toLocaleDateString([], {weekday: 'short'});
                    } else {
                        return date.toLocaleDateString([], {month: 'short', day: 'numeric'});
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