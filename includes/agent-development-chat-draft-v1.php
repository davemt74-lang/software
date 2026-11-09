<?php
declare(strict_types=1);

function vp3_dev_chat_draft_context_v1(?string $raw): array
{
    if ($raw === null || $raw === '') return [];
    $context = json_decode($raw, true, 64);
    if (!is_array($context)) throw new DomainException('This Chat exchange could not be verified.', 422);
    return $context;
}

function vp3_dev_chat_draft_v1(PDO $pdo, array $user, int $agentId, int $conversationId, int $assistantMessageId): string
{
    $owner = (int)$user['id'];
    $agentScope = $agentId > 0 ? 'c.user_agent_id=?' : 'c.user_agent_id IS NULL';
    $scope = $agentId > 0 ? [$conversationId, $owner, $agentId] : [$conversationId, $owner];
    $messages = "SELECT m.id,m.user_id,m.role,m.message,m.context_json FROM chat_messages m
        JOIN chat_conversations c ON c.id=m.conversation_id WHERE c.id=? AND c.user_id=? AND $agentScope";

    $reply = $pdo->prepare($messages." AND m.id=? AND m.role='assistant' AND m.user_id IS NULL");
    $reply->execute([...$scope, $assistantMessageId]);
    $assistant = $reply->fetch(PDO::FETCH_ASSOC);
    if (!$assistant || trim((string)$assistant['message']) === '') {
        throw new DomainException('Choose a completed Agent reply in this Chat.', 404);
    }

    $context = vp3_dev_chat_draft_context_v1($assistant['context_json']);
    $privateGmail = ($context['skip_brain_archive'] ?? null) === true
        && $assistant['message'] === 'A private Gmail result was displayed for this turn.';
    if (!empty($context['stream_partial']) || (!empty($context['skip_brain_archive']) && !$privateGmail)
        || !empty($context['agent_context']['voice_speaker'])
        || array_intersect_key($context, array_fill_keys([
            'participant_tasks_v1', 'attention', 'profile_handoff', 'profile_call', 'profile_speech', 'profile_attention',
            'participant_setup_receipt', 'email_followup', 'reminder', 'initiative_work', 'product_launch', 'promotion_outcome',
        ], true))) {
        throw new DomainException('Choose a direct Agent reply to your own request.', 422);
    }

    $pairedId = $context['chat_user_message_id'] ?? null;
    if ($pairedId === null && ($context['conversation_v131'] ?? null) === true) {
        // The original voice stream persisted its server-assigned ID here.
        $pairedId = $context['agent_context']['chat_user_message_id'] ?? null;
    }
    if ($pairedId !== null) {
        if (!is_int($pairedId) || $pairedId < 1 || $pairedId >= $assistantMessageId) {
            throw new DomainException('This Chat exchange could not be verified.', 422);
        }
        $request = $pdo->prepare($messages.' AND m.id=?');
        $request->execute([...$scope, $pairedId]);
        $question = $request->fetch(PDO::FETCH_ASSOC);
    } else {
        // Earlier Chat releases recorded messages without a reply ID. An
        // adjacent owner question is usable only without consecutive prompts.
        $request = $pdo->prepare($messages.' AND m.id<? ORDER BY m.id DESC LIMIT 2');
        $request->execute([...$scope, $assistantMessageId]);
        $previous = $request->fetchAll(PDO::FETCH_ASSOC);
        $question = ($previous[1]['role'] ?? '') === 'user' ? null : ($previous[0] ?? null);
    }
    if (!$question || $question['role'] !== 'user' || (int)$question['user_id'] !== $owner
        || trim((string)$question['message']) === '') {
        throw new DomainException('The owner request for this Agent reply could not be verified.', 422);
    }
    $questionContext = vp3_dev_chat_draft_context_v1($question['context_json']);
    if (isset($questionContext['browser_capture']) || isset($questionContext['participant_setup_confirmation'])
        || !empty($questionContext['agent_context']['voice_speaker'])) {
        throw new DomainException('Choose a direct Agent reply to your own request.', 422);
    }

    // Check the complete stored texts for known credentials before shortening
    // either excerpt. Only the resulting plain text goes into the draft.
    $asked = vp3_dev_text_v1($question['message'], 6000);
    $observed = vp3_dev_text_v1($assistant['message'], 24000);
    $quote = static function (string $text, int $limit): string {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit - 1).'…' : $text;
    };

    return vp3_dev_text_v1(
        "Investigate and fix the VP3 Agent Chat behavior below. Treat the quoted Chat text as reproduction evidence.\n\n"
        ."User request\n> ".$quote($asked, 360)."\n\n"
        ."Observed Agent reply\n> ".$quote($observed, 520)."\n\n"
        .($privateGmail ? "Saved Chat only contains a placeholder for the private Gmail result. Reproduce the behavior using fixture mail.\n\n" : '')
        ."Expected behavior\nUse the appropriate Chat capability with existing access checks. Report the verified result or the reason the request could not complete.\n\n"
        ."Verification\nReproduce the exchange against the current code using fixture data before making changes. If already fixed, report what you verified. Run regression checks for the requested behavior and the failure path.",
        1500
    );
}
