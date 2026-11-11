<?php
declare(strict_types=1);

require_once __DIR__.'/artist-listening-transcript.php';
require_once __DIR__.'/knowledge.php';

/** Verify the exact selection in the saved page or speaker turn. Browser offsets are UTF-16. */
function vp3_transcript_knowledge_highlight_v1(array $session, array $segments, int $pageNumber, int $segmentId, int $start, string $selection): array
{
    if (($session['status'] ?? '') !== 'draft') {
        throw new RuntimeException('Stop and save the transcript before highlighting it.');
    }
    if ($start < 0 || trim($selection) === '' || !mb_check_encoding($selection, 'UTF-8') || mb_strlen($selection) > 20000) {
        throw new RuntimeException('Select up to 20,000 characters of saved transcript text.');
    }
    $map = artist_listening_transcript_page_map($segments);
    $page = $map['pages'][$pageNumber - 1] ?? null;
    $text = '';
    $anchors = [];
    $position = 0;
    foreach ($page['segments'] ?? [] as $segment) {
        $id = (int)$segment['id'];
        if ($segment['segment_type'] !== 'transcript' || ($segmentId > 0 && $id !== $segmentId)) continue;
        $part = $segmentId > 0
            ? str_replace(["\r\n", "\r"], "\n", (string)$segment['transcript_text'])
            : artist_listening_v237_clean_text((string)$segment['transcript_text']);
        if ($part === '' || ($segmentId === 0 && $part === '0')) continue;
        if ($text !== '') {
            $text .= ' ';
            ++$position;
        }
        $length = intdiv(strlen(mb_convert_encoding($part, 'UTF-16LE', 'UTF-8')), 2);
        $anchors[] = ['id'=>$id, 'start'=>$position, 'end'=>$position + $length];
        $text .= $part;
        $position += $length;
    }
    $raw = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
    $excerpt = mb_convert_encoding($selection, 'UTF-16LE', 'UTF-8');
    if ($start >= $position || substr($raw, $start * 2, strlen($excerpt)) !== $excerpt) {
        throw new RuntimeException('That highlight does not match the saved transcript. Save your edits, then select the text again.');
    }
    $end = $start + intdiv(strlen($excerpt), 2);
    $covered = array_values(array_filter($anchors, static fn(array $anchor): bool => $start < $anchor['end'] && $end > $anchor['start']));
    if (!$covered) throw new RuntimeException('Select text from a saved transcript segment.');
    $first = $covered[0];
    $last = $covered[count($covered) - 1];
    $sessionId = (int)$session['id'];
    $identity = hash('sha256', json_encode([$first['id'], $start - $first['start'], $last['id'], $end - $last['start'], $selection], JSON_THROW_ON_ERROR));
    return ['key'=>'transcript-knowledge-highlight:'.$sessionId.':'.$identity, 'text'=>$selection,
        'source_url'=>url('/artist-listening-evidence.php?session='.$sessionId.'&page='.$pageNumber.'#transcript-segment-'.$first['id']),
        'segment_ids'=>array_column($covered, 'id'), 'page'=>$pageNumber];
}
