<?php
/**
 * Client simple pour l'API Claude (Anthropic) sans dépendances
 * Utilise cURL directement
 */

class ClaudeAPI
{
    private $apiKey;
    private $apiUrl = 'https://api.anthropic.com/v1/messages';
    private $model = 'claude-3-5-sonnet-20241022';
    private $maxTokens = 1024;

    public function __construct($apiKey)
    {
        $this->apiKey = $apiKey;
    }

    /**
     * Envoyer un message à Claude et recevoir une réponse
     *
     * @param string $userMessage Le message de l'utilisateur
     * @param string $systemPrompt Instructions système (optionnel)
     * @return array ['success' => bool, 'content' => string, 'error' => string]
     */
    public function sendMessage($userMessage, $systemPrompt = null)
    {
        // Préparer les données
        $data = [
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $userMessage
                ]
            ]
        ];

        // Ajouter le system prompt si fourni
        if ($systemPrompt) {
            $data['system'] = $systemPrompt;
        }

        // Préparer les headers
        $headers = [
            'Content-Type: application/json',
            'x-api-key: ' . $this->apiKey,
            'anthropic-version: 2023-06-01'
        ];

        // Initialiser cURL
        $ch = curl_init($this->apiUrl);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 30
        ]);

        // Exécuter la requête
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        curl_close($ch);

        // Vérifier les erreurs cURL
        if ($curlError) {
            return [
                'success' => false,
                'error' => 'Erreur cURL: ' . $curlError
            ];
        }

        // Décoder la réponse JSON
        $responseData = json_decode($response, true);

        // Vérifier les erreurs HTTP
        if ($httpCode !== 200) {
            $errorMessage = $responseData['error']['message'] ?? 'Erreur HTTP ' . $httpCode;
            return [
                'success' => false,
                'error' => $errorMessage
            ];
        }

        // Extraire le contenu de la réponse
        if (isset($responseData['content'][0]['text'])) {
            return [
                'success' => true,
                'content' => $responseData['content'][0]['text'],
                'usage' => $responseData['usage'] ?? null
            ];
        }

        return [
            'success' => false,
            'error' => 'Format de réponse invalide'
        ];
    }

    /**
     * Définir le modèle à utiliser
     */
    public function setModel($model)
    {
        $this->model = $model;
    }

    /**
     * Définir le nombre maximum de tokens
     */
    public function setMaxTokens($tokens)
    {
        $this->maxTokens = $tokens;
    }
}
