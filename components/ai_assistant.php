<?php
/**
 * Composant: Assistant IA Conversationnel
 * Widget de chat intégré dans toutes les pages
 */
?>

<!-- Bouton pour ouvrir le chat (fixe en bas à droite) -->
<button id="aiChatToggle" class="fixed bottom-6 right-6 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white p-4 rounded-full shadow-2xl transition-all duration-300 hover:scale-110 z-40 group">
    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"></path>
    </svg>
    <span class="absolute -top-2 -right-2 bg-red-500 text-white text-xs rounded-full w-5 h-5 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity">IA</span>
</button>

<!-- Modal de chat -->
<div id="aiChatModal" class="fixed bottom-24 right-6 w-96 bg-white rounded-2xl shadow-2xl hidden z-50 flex-col" style="height: 600px; max-height: 80vh;">
    <!-- Header -->
    <div class="bg-gradient-to-r from-blue-600 to-indigo-600 text-white p-4 rounded-t-2xl flex items-center justify-between">
        <div class="flex items-center space-x-3">
            <div class="w-10 h-10 bg-white/20 rounded-full flex items-center justify-center">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path>
                </svg>
            </div>
            <div>
                <h3 class="font-semibold text-lg">Assistant IA</h3>
                <p class="text-xs text-blue-100">Posez-moi vos questions comptables</p>
            </div>
        </div>
        <button id="aiChatClose" class="hover:bg-white/10 p-2 rounded-lg transition">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
        </button>
    </div>

    <!-- Messages Container -->
    <div id="aiChatMessages" class="flex-1 overflow-y-auto p-4 space-y-4 bg-gray-50">
        <!-- Message de bienvenue -->
        <div class="flex items-start space-x-3">
            <div class="w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center flex-shrink-0">
                <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path>
                </svg>
            </div>
            <div class="bg-white rounded-2xl rounded-tl-none p-3 shadow-sm max-w-xs">
                <p class="text-sm text-gray-800">
                    👋 Bonjour ! Je suis votre assistant comptable IA.
                    <br><br>
                    Vous pouvez me poser des questions comme :
                    <br>• "Quel est mon CA du mois ?"
                    <br>• "Montre-moi les factures impayées"
                    <br>• "Quelles sont mes charges ?"
                </p>
            </div>
        </div>
    </div>

    <!-- Input Zone -->
    <div class="p-4 bg-white border-t border-gray-200 rounded-b-2xl">
        <div class="flex items-end space-x-2">
            <div class="flex-1 relative">
                <textarea
                    id="aiChatInput"
                    rows="1"
                    placeholder="Posez votre question..."
                    class="w-full px-4 py-3 pr-12 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent resize-none max-h-32 text-gray-900 bg-white"
                    style="min-height: 44px;"
                ></textarea>
                <!-- Indicateur de typing -->
                <div id="aiTypingIndicator" class="absolute bottom-2 left-4 hidden">
                    <div class="flex space-x-1">
                        <div class="w-2 h-2 bg-blue-500 rounded-full animate-bounce" style="animation-delay: 0ms;"></div>
                        <div class="w-2 h-2 bg-blue-500 rounded-full animate-bounce" style="animation-delay: 150ms;"></div>
                        <div class="w-2 h-2 bg-blue-500 rounded-full animate-bounce" style="animation-delay: 300ms;"></div>
                    </div>
                </div>
            </div>
            <button
                id="aiChatSend"
                class="bg-blue-600 hover:bg-blue-700 text-white p-3 rounded-xl transition-colors flex-shrink-0"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path>
                </svg>
            </button>
        </div>
        <p class="text-xs text-gray-400 mt-2 text-center">Propulsé par Claude AI</p>
    </div>
</div>

<style>
#aiChatMessages::-webkit-scrollbar {
    width: 6px;
}
#aiChatMessages::-webkit-scrollbar-track {
    background: #f1f1f1;
}
#aiChatMessages::-webkit-scrollbar-thumb {
    background: #888;
    border-radius: 3px;
}
#aiChatMessages::-webkit-scrollbar-thumb:hover {
    background: #555;
}

.animate-bounce {
    animation: bounce 0.6s infinite;
}

@keyframes bounce {
    0%, 100% {
        transform: translateY(0);
    }
    50% {
        transform: translateY(-5px);
    }
}
</style>

<script>
(function() {
    const chatToggle = document.getElementById('aiChatToggle');
    const chatModal = document.getElementById('aiChatModal');
    const chatClose = document.getElementById('aiChatClose');
    const chatInput = document.getElementById('aiChatInput');
    const chatSend = document.getElementById('aiChatSend');
    const chatMessages = document.getElementById('aiChatMessages');
    const typingIndicator = document.getElementById('aiTypingIndicator');

    let isOpen = false;

    // Chemin absolu pour l'API
    const apiBasePath = '/comptabilite_ohada/api/v1/ai/chat.php';

    // Toggle chat
    chatToggle.addEventListener('click', () => {
        isOpen = !isOpen;
        if (isOpen) {
            chatModal.classList.remove('hidden');
            chatModal.classList.add('flex');
            chatInput.focus();
        } else {
            chatModal.classList.add('hidden');
            chatModal.classList.remove('flex');
        }
    });

    chatClose.addEventListener('click', () => {
        isOpen = false;
        chatModal.classList.add('hidden');
        chatModal.classList.remove('flex');
    });

    // Auto-resize textarea
    chatInput.addEventListener('input', function() {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 128) + 'px';
    });

    // Envoyer message
    function sendMessage() {
        const question = chatInput.value.trim();
        if (!question) return;

        // Afficher la question de l'utilisateur
        addUserMessage(question);
        chatInput.value = '';
        chatInput.style.height = 'auto';

        // Afficher l'indicateur de typing
        showTyping();

        // Envoyer à l'API
        fetch(apiBasePath, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ question: question })
        })
        .then(response => response.json())
        .then(data => {
            hideTyping();

            if (data.success) {
                addAIMessage(data.response, data.cached, data.intent);
            } else {
                addAIMessage('❌ Erreur: ' + (data.error || 'Impossible de traiter la question'), false);
            }
        })
        .catch(error => {
            hideTyping();
            addAIMessage('❌ Erreur de connexion: ' + error.message, false);
        });
    }

    chatSend.addEventListener('click', sendMessage);

    chatInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });

    // Afficher message utilisateur
    function addUserMessage(text) {
        const messageDiv = document.createElement('div');
        messageDiv.className = 'flex items-start space-x-3 justify-end';
        messageDiv.innerHTML = `
            <div class="bg-blue-600 text-white rounded-2xl rounded-tr-none p-3 shadow-sm max-w-xs">
                <p class="text-sm">${escapeHtml(text)}</p>
            </div>
            <div class="w-8 h-8 bg-gray-300 rounded-full flex items-center justify-center flex-shrink-0">
                <svg class="w-5 h-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                </svg>
            </div>
        `;
        chatMessages.appendChild(messageDiv);
        scrollToBottom();
    }

    // Afficher message IA
    function addAIMessage(text, cached = false, intent = null) {
        const messageDiv = document.createElement('div');
        messageDiv.className = 'flex items-start space-x-3';

        const cacheBadge = cached ? '<span class="text-xs bg-green-100 text-green-700 px-2 py-1 rounded-full">⚡ Cache</span>' : '';
        const intentBadge = intent ? `<span class="text-xs bg-gray-100 text-gray-600 px-2 py-1 rounded-full">${intent}</span>` : '';

        messageDiv.innerHTML = `
            <div class="w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center flex-shrink-0">
                <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path>
                </svg>
            </div>
            <div class="bg-white rounded-2xl rounded-tl-none p-3 shadow-sm max-w-xs">
                <p class="text-sm text-gray-800 whitespace-pre-line">${escapeHtml(text)}</p>
                <div class="flex space-x-2 mt-2">
                    ${cacheBadge}
                    ${intentBadge}
                </div>
            </div>
        `;
        chatMessages.appendChild(messageDiv);
        scrollToBottom();
    }

    function showTyping() {
        const typingDiv = document.createElement('div');
        typingDiv.id = 'typingMessage';
        typingDiv.className = 'flex items-start space-x-3';
        typingDiv.innerHTML = `
            <div class="w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center flex-shrink-0">
                <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path>
                </svg>
            </div>
            <div class="bg-white rounded-2xl rounded-tl-none p-3 shadow-sm">
                <div class="flex space-x-1">
                    <div class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 0ms;"></div>
                    <div class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 150ms;"></div>
                    <div class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 300ms;"></div>
                </div>
            </div>
        `;
        chatMessages.appendChild(typingDiv);
        scrollToBottom();
    }

    function hideTyping() {
        const typingDiv = document.getElementById('typingMessage');
        if (typingDiv) {
            typingDiv.remove();
        }
    }

    function scrollToBottom() {
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
})();
</script>
