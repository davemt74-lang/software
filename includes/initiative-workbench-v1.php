<?php
declare(strict_types=1);

function initiative_workbench_input_v1(array $body): array
{
    $source = $body['source_id'] ?? null;
    if (!is_string($source) || !preg_match('/\A[a-z0-9-]{1,80}\z/D', $source)) {
        throw new DomainException('Choose an exact saved source.', 422);
    }
    $input = ['source_id' => $source];
    if ($body['tool'] === 'product' && $source === 'description') {
        $input['product_description'] = initiative_work_product_text_v1($body['product_description'] ?? null, 600, true);
    }
    if ($body['tool'] === 'screen') {
        $mode = $body['mode'] ?? '';
        $instruction = $body['instruction'] ?? '';
        if (!in_array($mode, ['cleanup', 'redraw'], true) || !is_string($instruction)
            || mb_strlen($instruction) > 600 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $instruction)) {
            throw new DomainException('Choose Cleanup or Redraw and keep directions under 600 characters.', 422);
        }
        $input += ['mode' => $mode, 'instruction' => trim($instruction)];
    }
    return $input;
}

function initiative_workbench_request_v1(int $owner, array $scope, string $tool, array $input, array $preview): string
{
    return hash('sha256', json_encode([
        $owner, $scope['agent_id'], $scope['conversation_id'], $tool, $input, $preview['revision'],
    ], JSON_THROW_ON_ERROR));
}

function initiative_workbench_review_token_v1(string $key, string $request, int $expires): string
{
    return $expires . '.' . hash_hmac('sha256', 'initiative-workbench-v1:' . $request . ':' . $expires, $key);
}

function initiative_workbench_confirm_v1(array $body, string $key, string $request, int $now): void
{
    $token = $body['review_token'] ?? '';
    if (($body['confirmed'] ?? null) !== true || !is_string($token)
        || !preg_match('/\A([0-9]{1,12})\.[a-f0-9]{64}\z/D', $token, $matches)) {
        throw new DomainException('Review the source and confirm before creating a result.', 422);
    }
    $expires = (int)$matches[1];
    if ($expires <= $now || $expires > $now + 600
        || !hash_equals(initiative_workbench_review_token_v1($key, $request, $expires), $token)) {
        throw new DomainException('The source or proposal changed, or the review expired. Review it again.', 409);
    }
}
