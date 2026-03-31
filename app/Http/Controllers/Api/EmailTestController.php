<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class EmailTestController extends Controller
{
    public function index(Request $request)
    {
        $payload = [
            'Recipient' => $request->input('Recipient', $request->input('recipient')),
            'Subject' => $request->input('Subject', $request->input('subject')),
            'Body' => $request->input('Body', $request->input('body')),
            'Variables' => $request->input('Variables', $request->input('variables', [])),
        ];

        $validator = Validator::make($payload, [
            'Recipient' => 'required|email|max:255',
            'Subject' => 'required|string|max:255',
            'Body' => 'required|string',
            'Variables' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $body = (string) $payload['Body'];
            $variables = is_array($payload['Variables']) ? $payload['Variables'] : [];

            $replacementPairs = [];
            foreach ($variables as $key => $value) {
                if (!is_string($key) || trim($key) === '') {
                    continue;
                }

                if (is_array($value) || is_object($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }

                $replacementPairs['{{' . trim($key) . '}}'] = (string) $value;
            }

            if (!empty($replacementPairs)) {
                $body = strtr($body, $replacementPairs);
            }

            $containsHtml = $body !== strip_tags($body);
            preg_match_all('/{{\s*([a-zA-Z0-9_]+)\s*}}/', $body, $remainingMatches);
            $unreplacedPlaceholders = array_values(array_unique($remainingMatches[0] ?? []));

            if ($containsHtml) {
                Mail::html($body, function ($message) use ($payload) {
                    $message->to($payload['Recipient'])->subject($payload['Subject']);
                });
            } else {
                Mail::raw($body, function ($message) use ($payload) {
                    $message->to($payload['Recipient'])->subject($payload['Subject']);
                });
            }

            return response()->json([
                'success' => true,
                'message' => 'Test email sent successfully',
                'data' => [
                    'Recipient' => $payload['Recipient'],
                    'Subject' => $payload['Subject'],
                    'Variables' => $variables,
                    'UnreplacedPlaceholders' => $unreplacedPlaceholders,
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::warning('Failed to send test email', [
                'recipient' => $payload['Recipient'],
                'subject' => $payload['Subject'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to send test email',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
