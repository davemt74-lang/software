<?php
declare(strict_types=1);

function vp3_dev_chat_draft_context_v1(?string $raw): array
{
    if ($raw === null || $raw === '') return [];
    $context = json_decode($raw, true, 64);
    if (!is_array($context)) throw new DomainException('This Chat exchange could not be verified.', 422);
    return $context;
}

function vp3_dev_chat_draft_quote_v1(string $text, int $limit): string
{
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit - 1).'…' : $text;
}

function vp3_dev_chat_draft_question_v1(array $question): string
{
    $context = vp3_dev_chat_draft_context_v1($question['context_json']);
    if (isset($context['browser_capture']) || isset($context['participant_setup_confirmation'])
        || !empty($context['agent_context']['voice_speaker'])) {
        throw new DomainException('Choose a direct Agent reply to your own request.', 422);
    }
    $text = vp3_dev_chat_draft_quote_v1(vp3_dev_text_v1($question['message'], 6000), 360);
    if ($text === '') throw new DomainException('Choose a saved owner request with text.', 422);
    return $text;
}

function vp3_dev_chat_draft_v1(PDO $pdo, array $user, int $agentId, int $conversationId, int $assistantMessageId, ?int $userMessageId = null): array
{
    $owner = (int)$user['id'];
    $agentScope = $agentId > 0 ? 'c.user_agent_id=?' : 'c.user_agent_id IS NULL';
    $scope = $agentId > 0 ? [$conversationId, $owner, $agentId] : [$conversationId, $owner];
    $messages = "SELECT m.id,m.message,m.context_json FROM chat_messages m
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

    // The original voice stream persisted its server-assigned ID in agent_context.
    $link = !array_key_exists('chat_user_message_id', $context) && ($context['conversation_v131'] ?? null) === true
        && is_array($context['agent_context'] ?? null) ? $context['agent_context'] : $context;
    // Check the full stored reply for known credentials before shortening it.
    $observed = vp3_dev_chat_draft_quote_v1(vp3_dev_text_v1($assistant['message'], 24000), 520);
    $questions = $messages." AND m.role='user' AND m.user_id=c.user_id";
    if (array_key_exists('chat_user_message_id', $link)) {
        $pairedId = $link['chat_user_message_id'];
        if (!is_int($pairedId) || $pairedId < 1 || $pairedId >= $assistantMessageId) {
            throw new DomainException('This Chat exchange could not be verified.', 422);
        }
        if ($userMessageId !== null && $userMessageId !== $pairedId) {
            throw new DomainException('Use the original request linked to this Agent reply.', 422);
        }
        $request = $pdo->prepare($questions.' AND m.id=?');
        $request->execute([...$scope, $pairedId]);
        $question = $request->fetch(PDO::FETCH_ASSOC);
        if (!$question || trim((string)$question['message']) === '') {
            throw new DomainException('The owner request for this Agent reply could not be verified.', 422);
        }
        $asked = vp3_dev_chat_draft_question_v1($question);
    } else {
        // Earlier Chat releases did not always store reply IDs. Offer a bounded
        // set of nearby owner prompts and require the admin to select one.
        $request = $pdo->prepare($questions.' AND m.id<? ORDER BY m.id DESC LIMIT 8');
        $request->execute([...$scope, $assistantMessageId]);
        $options = [];
        foreach ($request->fetchAll(PDO::FETCH_ASSOC) as $question) {
            try {
                $options[(int)$question['id']] = vp3_dev_chat_draft_question_v1($question);
            } catch (DomainException) {
                // Private, malformed or credential-bearing text is not an option.
            }
        }
        if ($userMessageId === null) {
            $prompts = [];
            foreach ($options as $id => $text) $prompts[] = ['id'=>$id, 'text'=>$text];
            return ['legacy_prompts'=>$prompts];
        }
        $asked = $options[$userMessageId] ?? throw new DomainException('Choose an available request for this Agent reply.', 422);
    }

    return ['draft'=>vp3_dev_text_v1(
        "Investigate and fix the VP3 Agent Chat behavior below. Treat the quoted Chat text as reproduction evidence.\n\n"
        ."User request\n> ".$asked."\n\n"
        ."Observed Agent reply\n> ".$observed."\n\n"
        .($privateGmail ? "Saved Chat only contains a placeholder for the private Gmail result. Reproduce the behavior using fixture mail.\n\n" : '')
        ."Expected behavior\nUse the appropriate Chat capability with existing access checks. Report the verified result or the reason the request could not complete.\n\n"
        ."Verification\nReproduce the exchange against the current code using fixture data before making changes. If already fixed, report what you verified. Run regression checks for the requested behavior and the failure path.",
        1500
    )];
}
