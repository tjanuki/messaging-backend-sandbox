<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Real-time Messaging</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://js.pusher.com/8.2.0/pusher.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/laravel-echo@1.15.0/dist/echo.iife.js"></script>
</head>
<body class="bg-gray-100 h-screen flex flex-col">
    <div id="app" class="flex-1 flex flex-col">
        <!-- Header -->
        <header class="bg-blue-600 text-white p-4 shadow">
            <div class="flex justify-between items-center">
                <h1 class="text-xl font-bold">Real-time Messaging</h1>
                <div class="flex items-center space-x-4">
                    <span id="user-status" class="text-sm px-2 py-1 rounded bg-green-500">Online</span>
                    <span id="current-user" class="text-sm"></span>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <div class="flex-1 flex overflow-hidden">
            <!-- Conversations List -->
            <div class="w-1/3 bg-white border-r border-gray-200 flex flex-col">
                <div class="p-4 border-b border-gray-200">
                    <h2 class="font-semibold text-gray-800">Conversations</h2>
                    <button onclick="createTestConversation()" class="mt-2 bg-blue-500 text-white px-3 py-1 rounded text-sm">
                        Create Test Conversation
                    </button>
                </div>
                <div class="flex-1 overflow-y-auto" id="conversations-list">
                    <!-- Conversations will be loaded here -->
                </div>
            </div>

            <!-- Messages Area -->
            <div class="flex-1 flex flex-col">
                <!-- Conversation Header -->
                <div class="p-4 border-b border-gray-200 bg-white" id="conversation-header" style="display: none;">
                    <h3 id="conversation-title" class="font-semibold text-gray-800"></h3>
                    <div id="online-users" class="text-sm text-gray-500 mt-1"></div>
                    <div id="typing-indicator" class="text-sm text-gray-500 italic" style="display: none;"></div>
                </div>

                <!-- Messages Container -->
                <div class="flex-1 overflow-y-auto p-4 space-y-4" id="messages-container">
                    <div class="text-center text-gray-500">
                        Select a conversation to start messaging
                    </div>
                </div>

                <!-- Message Input -->
                <div class="p-4 border-t border-gray-200 bg-white" id="message-input-area" style="display: none;">
                    <div class="flex space-x-2">
                        <input 
                            type="text" 
                            id="message-input" 
                            placeholder="Type a message..." 
                            class="flex-1 border border-gray-300 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                            onkeypress="handleKeyPress(event)"
                            oninput="handleTyping()"
                        >
                        <button 
                            onclick="sendMessage()" 
                            class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium"
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
        // Global variables
        let currentConversationId = null;
        let currentUser = null;
        let apiToken = 'test-token'; // In real app, this would come from authentication
        let echo = null;
        let typingTimeout = null;
        let isTyping = false;

        // Initialize app
        document.addEventListener('DOMContentLoaded', function() {
            initializeAuth();
            setupWebSocket();
            loadConversations();
            startHeartbeat();
        });

        function initializeAuth() {
            // In a real app, you would authenticate here
            currentUser = {
                id: 1,
                name: 'Test User',
                email: 'test@example.com'
            };
            document.getElementById('current-user').textContent = currentUser.name;
        }

        function setupWebSocket() {
            // Initialize Laravel Echo with Pusher
            echo = new Echo({
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

            console.log('WebSocket initialized');
        }

        function subscribeToConversation(conversationId) {
            if (!echo) return;

            // Subscribe to conversation channel
            echo.private(`conversation.${conversationId}`)
                .listen('message.sent', (e) => {
                    console.log('New message received:', e);
                    if (e.message.user_id !== currentUser.id) {
                        addMessageToUI(e.message);
                    }
                })
                .listen('message.updated', (e) => {
                    console.log('Message updated:', e);
                    updateMessageInUI(e.message);
                })
                .listen('message.deleted', (e) => {
                    console.log('Message deleted:', e);
                    removeMessageFromUI(e.message_id);
                })
                .listen('user.typing.start', (e) => {
                    console.log('User started typing:', e);
                    if (e.user.id !== currentUser.id) {
                        showTypingIndicator(e.user.name);
                    }
                })
                .listen('user.typing.stop', (e) => {
                    console.log('User stopped typing:', e);
                    if (e.user.id !== currentUser.id) {
                        hideTypingIndicator(e.user.name);
                    }
                })
                .listen('user.online', (e) => {
                    console.log('User came online:', e);
                    updateUserOnlineStatus(e.user, true);
                })
                .listen('user.offline', (e) => {
                    console.log('User went offline:', e);
                    updateUserOnlineStatus(e.user, false);
                });
        }

        function loadConversations() {
            // Mock conversation data for demonstration
            const mockConversations = [
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
            ];

            renderConversations(mockConversations);
        }

        function renderConversations(conversations) {
            const container = document.getElementById('conversations-list');
            container.innerHTML = conversations.map(conversation => `
                <div class="p-4 border-b border-gray-100 hover:bg-gray-50 cursor-pointer ${
                    currentConversationId === conversation.id ? 'bg-blue-50 border-l-4 border-l-blue-500' : ''
                }" onclick="selectConversation(${conversation.id}, '${conversation.name}')">
                    <div class="flex justify-between items-start">
                        <div class="flex-1">
                            <h3 class="font-medium text-gray-900">${conversation.name}</h3>
                            <p class="text-sm text-gray-500 mt-1 truncate">${conversation.last_message?.content || 'No messages yet'}</p>
                        </div>
                        <span class="text-xs text-gray-400">${formatTime(conversation.last_message_at)}</span>
                    </div>
                </div>
            `).join('');
        }

        function selectConversation(conversationId, conversationName) {
            if (currentConversationId === conversationId) return;

            currentConversationId = conversationId;
            
            // Update UI
            document.getElementById('conversation-header').style.display = 'block';
            document.getElementById('conversation-title').textContent = conversationName;
            document.getElementById('message-input-area').style.display = 'block';
            
            // Refresh conversations list to show selection
            loadConversations();
            
            // Load messages for this conversation
            loadMessages(conversationId);
            
            // Subscribe to real-time updates
            subscribeToConversation(conversationId);
            
            // Load online users
            loadOnlineUsers();
        }

        function loadMessages(conversationId) {
            // Mock message data for demonstration
            const mockMessages = [
                {
                    id: 1,
                    user_id: 2,
                    content: 'Hello! How are you?',
                    created_at: new Date(Date.now() - 300000).toISOString(),
                    user: { id: 2, name: 'Other User', avatar: null }
                },
                {
                    id: 2,
                    user_id: currentUser.id,
                    content: 'I\'m doing great, thanks!',
                    created_at: new Date(Date.now() - 180000).toISOString(),
                    user: currentUser
                },
                {
                    id: 3,
                    user_id: 2,
                    content: 'That\'s wonderful to hear!',
                    created_at: new Date(Date.now() - 60000).toISOString(),
                    user: { id: 2, name: 'Other User', avatar: null }
                }
            ];

            renderMessages(mockMessages);
        }

        function renderMessages(messages) {
            const container = document.getElementById('messages-container');
            container.innerHTML = messages.map(message => `
                <div class="flex ${message.user_id === currentUser.id ? 'justify-end' : 'justify-start'}" data-message-id="${message.id}">
                    <div class="max-w-xs lg:max-w-md px-4 py-2 rounded-lg ${
                        message.user_id === currentUser.id 
                            ? 'bg-blue-600 text-white' 
                            : 'bg-white text-gray-900 border border-gray-200'
                    }">
                        ${message.user_id !== currentUser.id ? `<p class="text-sm font-medium ${message.user_id === currentUser.id ? 'text-blue-100' : 'text-gray-500'} mb-1">${message.user.name}</p>` : ''}
                        <p>${message.content}</p>
                        <p class="text-xs opacity-75 mt-1">${formatTime(message.created_at)}</p>
                    </div>
                </div>
            `).join('');
            
            // Scroll to bottom
            container.scrollTop = container.scrollHeight;
        }

        function sendMessage() {
            const input = document.getElementById('message-input');
            const content = input.value.trim();
            
            if (!content || !currentConversationId) return;
            
            // Stop typing indicator
            stopTyping();
            
            // Create message object for immediate UI update
            const message = {
                id: Date.now(), // Temporary ID
                user_id: currentUser.id,
                content: content,
                created_at: new Date().toISOString(),
                user: currentUser
            };
            
            // Add to UI immediately
            addMessageToUI(message);
            
            // Clear input
            input.value = '';
            
            // In a real app, you would send this to the API
            console.log('Sending message:', content);
            
            // Simulate API call
            setTimeout(() => {
                console.log('Message sent successfully');
            }, 500);
        }

        function addMessageToUI(message) {
            const container = document.getElementById('messages-container');
            const messageElement = document.createElement('div');
            messageElement.className = `flex ${message.user_id === currentUser.id ? 'justify-end' : 'justify-start'}`;
            messageElement.setAttribute('data-message-id', message.id);
            messageElement.innerHTML = `
                <div class="max-w-xs lg:max-w-md px-4 py-2 rounded-lg ${
                    message.user_id === currentUser.id 
                        ? 'bg-blue-600 text-white' 
                        : 'bg-white text-gray-900 border border-gray-200'
                }">
                    ${message.user_id !== currentUser.id ? `<p class="text-sm font-medium ${message.user_id === currentUser.id ? 'text-blue-100' : 'text-gray-500'} mb-1">${message.user.name}</p>` : ''}
                    <p>${message.content}</p>
                    <p class="text-xs opacity-75 mt-1">${formatTime(message.created_at)}</p>
                </div>
            `;
            container.appendChild(messageElement);
            container.scrollTop = container.scrollHeight;
        }

        function updateMessageInUI(message) {
            const messageElement = document.querySelector(`[data-message-id="${message.id}"]`);
            if (messageElement) {
                // Update message content
                const contentElement = messageElement.querySelector('p:not(.text-xs)');
                if (contentElement) {
                    contentElement.textContent = message.content + ' (edited)';
                }
            }
        }

        function removeMessageFromUI(messageId) {
            const messageElement = document.querySelector(`[data-message-id="${messageId}"]`);
            if (messageElement) {
                messageElement.remove();
            }
        }

        function handleKeyPress(event) {
            if (event.key === 'Enter') {
                sendMessage();
            }
        }

        function handleTyping() {
            if (!currentConversationId) return;
            
            if (!isTyping) {
                startTyping();
            }
            
            // Clear existing timeout
            clearTimeout(typingTimeout);
            
            // Set timeout to stop typing after 3 seconds of inactivity
            typingTimeout = setTimeout(() => {
                stopTyping();
            }, 3000);
        }

        function startTyping() {
            if (isTyping) return;
            
            isTyping = true;
            console.log('Started typing in conversation', currentConversationId);
            
            // In a real app, you would call the API
            // fetch(`/api/conversations/${currentConversationId}/typing/start`, { method: 'POST', ... })
        }

        function stopTyping() {
            if (!isTyping) return;
            
            isTyping = false;
            clearTimeout(typingTimeout);
            console.log('Stopped typing in conversation', currentConversationId);
            
            // In a real app, you would call the API
            // fetch(`/api/conversations/${currentConversationId}/typing/stop`, { method: 'POST', ... })
        }

        function showTypingIndicator(userName) {
            const indicator = document.getElementById('typing-indicator');
            indicator.textContent = `${userName} is typing...`;
            indicator.style.display = 'block';
        }

        function hideTypingIndicator(userName) {
            const indicator = document.getElementById('typing-indicator');
            indicator.style.display = 'none';
        }

        function loadOnlineUsers() {
            // Mock online users data
            const mockOnlineUsers = ['User 1', 'User 2', 'User 3'];
            document.getElementById('online-users').textContent = 
                `Online: ${mockOnlineUsers.join(', ')}`;
        }

        function updateUserOnlineStatus(user, isOnline) {
            console.log(`${user.name} is now ${isOnline ? 'online' : 'offline'}`);
            loadOnlineUsers(); // Refresh online users list
        }

        function startHeartbeat() {
            // Send heartbeat every 30 seconds to maintain online status
            setInterval(() => {
                console.log('Sending heartbeat...');
                // In a real app: fetch('/api/user/heartbeat', { method: 'POST', ... })
            }, 30000);
        }

        function createTestConversation() {
            const name = prompt('Enter conversation name:');
            if (name) {
                console.log('Creating conversation:', name);
                // In a real app, you would call the API to create the conversation
                // Then refresh the conversations list
                setTimeout(() => {
                    loadConversations();
                }, 500);
            }
        }

        function formatTime(timestamp) {
            const date = new Date(timestamp);
            const now = new Date();
            const diffInHours = (now - date) / (1000 * 60 * 60);
            
            if (diffInHours < 24) {
                return date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
            } else {
                return date.toLocaleDateString();
            }
        }

        // Log WebSocket connection events
        if (echo) {
            echo.connector.pusher.connection.bind('connected', () => {
                console.log('WebSocket connected');
                document.getElementById('user-status').textContent = 'Online';
                document.getElementById('user-status').className = 'text-sm px-2 py-1 rounded bg-green-500';
            });

            echo.connector.pusher.connection.bind('disconnected', () => {
                console.log('WebSocket disconnected');
                document.getElementById('user-status').textContent = 'Offline';
                document.getElementById('user-status').className = 'text-sm px-2 py-1 rounded bg-red-500';
            });
        }
    </script>
</body>
</html>