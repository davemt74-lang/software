<?php
declare(strict_types=1);

const VP3_SUBSCRIPTION_INTELLIGENCE_BUILD='subscription-intelligence-20260906-v1';

function subscription_intelligence_ready(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)$pdo&&subscription_schema_ready($pdo)&&billing_schema_ready($pdo);
}

function subscription_intelligence_current_where(string $alias='s'): string
{
    return $alias.".status IN ('trialing','active','complimentary')"
        .' AND '.$alias.'.starts_at<=NOW()'
        .' AND ('.$alias.'.ends_at IS NULL OR '.$alias.'.ends_at>NOW())';
}

function subscription_intelligence_summary(?PDO $pdo=null): array
{
    $pdo??=db();
    if(!$pdo||!subscription_intelligence_ready($pdo))return ['available'=>false];

    $currentWhere=subscription_intelligence_current_where('s');
    $counts=$pdo->query("SELECT
      COUNT(DISTINCT CASE WHEN s.status='trialing' THEN s.user_id END) trialing,
      COUNT(DISTINCT CASE WHEN s.status='active' THEN s.user_id END) active,
      COUNT(DISTINCT CASE WHEN s.status='complimentary' THEN s.user_id END) complimentary,
      COUNT(DISTINCT s.user_id) current_accounts
      FROM user_subscriptions s WHERE {$currentWhere}")->fetch()?:[];

    $paid=$pdo->query("SELECT
      COUNT(DISTINCT bs.user_id) paid_accounts,
      COALESCE(SUM(CASE
        WHEN bp.billing_interval='annual' THEN ROUND(bp.unit_amount_cents/12)
        ELSE bp.unit_amount_cents END),0) mrr_cents
      FROM billing_subscriptions bs
      INNER JOIN package_billing_prices bp ON bp.provider=bs.provider AND bp.provider_price_id=bs.provider_price_id
      WHERE bs.provider='stripe' AND bs.status='active'")->fetch()?:[];
    $mrr=max(0,(int)($paid['mrr_cents']??0));

    $trialHistory=(int)$pdo->query("SELECT COUNT(DISTINCT s.user_id)
      FROM user_subscriptions s INNER JOIN subscription_packages p ON p.id=s.package_id
      WHERE p.is_trial=1")->fetchColumn();
    $trialConverted=(int)$pdo->query("SELECT COUNT(DISTINCT t.user_id)
      FROM (SELECT DISTINCT s.user_id FROM user_subscriptions s INNER JOIN subscription_packages p ON p.id=s.package_id WHERE p.is_trial=1) t
      INNER JOIN billing_subscriptions bs ON bs.user_id=t.user_id AND bs.provider='stripe'")->fetchColumn();

    $ai30=$pdo->query("SELECT COUNT(*) requests,COALESCE(SUM(total_tokens),0) tokens,
      COALESCE(SUM(input_tokens),0) input_tokens,COALESCE(SUM(output_tokens),0) output_tokens
      FROM ai_usage_ledger WHERE created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)")->fetch()?:[];

    $credits30=$pdo->query("SELECT
      COALESCE(SUM(CASE WHEN source='purchased_topup' THEN amount ELSE 0 END),0) purchased_tokens,
      COALESCE(SUM(CASE WHEN source<>'purchased_topup' THEN amount ELSE 0 END),0) other_credit_tokens,
      COUNT(CASE WHEN source='purchased_topup' THEN 1 END) purchased_credit_count
      FROM ai_token_credits WHERE created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)")->fetch()?:[];

    $tokenRevenue=['sales30'=>0,'revenue30_cents'=>0,'sales_all'=>0,'revenue_all_cents'=>0];
    if(token_pack_schema_ready($pdo)){
        $row=$pdo->query("SELECT
          COUNT(CASE WHEN status='credited' AND credited_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) THEN 1 END) sales30,
          COALESCE(SUM(CASE WHEN status='credited' AND credited_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) THEN price_cents ELSE 0 END),0) revenue30_cents,
          COUNT(CASE WHEN status='credited' THEN 1 END) sales_all,
          COALESCE(SUM(CASE WHEN status='credited' THEN price_cents ELSE 0 END),0) revenue_all_cents
          FROM ai_token_pack_purchases")->fetch()?:[];
        $tokenRevenue=array_merge($tokenRevenue,$row);
    }

    return [
        'available'=>true,
        'current_accounts'=>(int)($counts['current_accounts']??0),
        'trialing'=>(int)($counts['trialing']??0),
        'active'=>(int)($counts['active']??0),
        'complimentary'=>(int)($counts['complimentary']??0),
        'paid_accounts'=>(int)($paid['paid_accounts']??0),
        'mrr_cents'=>$mrr,
        'arr_cents'=>$mrr*12,
        'trial_started_accounts'=>$trialHistory,
        'trial_converted_accounts'=>$trialConverted,
        'trial_conversion_percent'=>$trialHistory>0?round(($trialConverted/$trialHistory)*100,1):0.0,
        'ai_requests_30d'=>(int)($ai30['requests']??0),
        'ai_tokens_30d'=>(int)($ai30['tokens']??0),
        'ai_input_tokens_30d'=>(int)($ai30['input_tokens']??0),
        'ai_output_tokens_30d'=>(int)($ai30['output_tokens']??0),
        'purchased_tokens_30d'=>(int)($credits30['purchased_tokens']??0),
        'other_credit_tokens_30d'=>(int)($credits30['other_credit_tokens']??0),
        'purchased_credit_count_30d'=>(int)($credits30['purchased_credit_count']??0),
        'token_pack_sales_30d'=>(int)($tokenRevenue['sales30']??0),
        'token_pack_revenue_30d_cents'=>(int)($tokenRevenue['revenue30_cents']??0),
        'token_pack_sales_all'=>(int)($tokenRevenue['sales_all']??0),
        'token_pack_revenue_all_cents'=>(int)($tokenRevenue['revenue_all_cents']??0),
    ];
}

function subscription_intelligence_package_mix(?PDO $pdo=null): array
{
    $pdo??=db();if(!$pdo||!subscription_intelligence_ready($pdo))return [];
    $where=subscription_intelligence_current_where('s');
    return $pdo->query("SELECT p.id,p.name,p.slug,p.is_trial,
      COUNT(DISTINCT s.user_id) account_count,
      COUNT(DISTINCT CASE WHEN s.status='trialing' THEN s.user_id END) trialing_count,
      COUNT(DISTINCT CASE WHEN s.status='active' THEN s.user_id END) active_count,
      COUNT(DISTINCT CASE WHEN s.status='complimentary' THEN s.user_id END) complimentary_count
      FROM subscription_packages p
      LEFT JOIN user_subscriptions s ON s.package_id=p.id AND {$where}
      GROUP BY p.id,p.name,p.slug,p.is_trial,p.sort_order
      HAVING account_count>0
      ORDER BY account_count DESC,p.sort_order,p.name")->fetchAll()?:[];
}

function subscription_intelligence_trials_ending(int $days=7,?PDO $pdo=null): array
{
    $pdo??=db();if(!$pdo||!subscription_intelligence_ready($pdo))return [];$days=max(1,min(90,$days));
    $stmt=$pdo->prepare("SELECT s.id subscription_id,s.user_id,s.starts_at,s.ends_at,s.current_period_end,
      u.display_name,u.email,p.name package_name,p.trial_tokens,
      COALESCE((SELECT SUM(l.total_tokens) FROM ai_usage_ledger l WHERE l.subscription_id=s.id),0) tokens_used
      FROM user_subscriptions s
      INNER JOIN users u ON u.id=s.user_id
      INNER JOIN subscription_packages p ON p.id=s.package_id
      WHERE s.status='trialing' AND s.starts_at<=NOW()
        AND COALESCE(s.ends_at,s.current_period_end) IS NOT NULL
        AND COALESCE(s.ends_at,s.current_period_end)>=NOW()
        AND COALESCE(s.ends_at,s.current_period_end)<DATE_ADD(NOW(),INTERVAL {$days} DAY)
      ORDER BY COALESCE(s.ends_at,s.current_period_end),s.id");
    $stmt->execute();return $stmt->fetchAll()?:[];
}

function subscription_intelligence_ai_by_package(int $days=30,?PDO $pdo=null): array
{
    $pdo??=db();if(!$pdo||!subscription_intelligence_ready($pdo))return [];$days=max(1,min(365,$days));
    return $pdo->query("SELECT COALESCE(p.name,'Unassigned / historical') package_name,
      COUNT(l.id) requests,COALESCE(SUM(l.total_tokens),0) tokens,
      COALESCE(SUM(l.input_tokens),0) input_tokens,COALESCE(SUM(l.output_tokens),0) output_tokens,
      COUNT(DISTINCT l.user_id) users
      FROM ai_usage_ledger l
      LEFT JOIN user_subscriptions s ON s.id=l.subscription_id
      LEFT JOIN subscription_packages p ON p.id=s.package_id
      WHERE l.created_at>=DATE_SUB(NOW(),INTERVAL {$days} DAY)
      GROUP BY p.id,p.name
      ORDER BY tokens DESC,requests DESC")->fetchAll()?:[];
}

function subscription_intelligence_ai_by_scope(int $days=30,?PDO $pdo=null): array
{
    $pdo??=db();if(!$pdo||!subscription_intelligence_ready($pdo))return [];$days=max(1,min(365,$days));
    return $pdo->query("SELECT scope,COUNT(*) requests,COALESCE(SUM(total_tokens),0) tokens,COUNT(DISTINCT user_id) users
      FROM ai_usage_ledger WHERE created_at>=DATE_SUB(NOW(),INTERVAL {$days} DAY)
      GROUP BY scope ORDER BY tokens DESC,requests DESC LIMIT 20")->fetchAll()?:[];
}

function subscription_intelligence_heavy_users(int $days=30,int $limit=20,?PDO $pdo=null): array
{
    $pdo??=db();if(!$pdo||!subscription_intelligence_ready($pdo))return [];$days=max(1,min(365,$days));$limit=max(1,min(100,$limit));
    return $pdo->query("SELECT l.user_id,u.display_name,u.email,COUNT(*) requests,COALESCE(SUM(l.total_tokens),0) tokens,
      COALESCE(SUM(l.credit_tokens_used),0) credit_tokens_used,COALESCE(SUM(l.package_tokens_used),0) package_tokens_used,
      (SELECT p.name FROM user_subscriptions cs INNER JOIN subscription_packages p ON p.id=cs.package_id
        WHERE cs.user_id=l.user_id AND ".subscription_intelligence_current_where('cs')."
        ORDER BY cs.starts_at DESC,cs.id DESC LIMIT 1) package_name
      FROM ai_usage_ledger l INNER JOIN users u ON u.id=l.user_id
      WHERE l.created_at>=DATE_SUB(NOW(),INTERVAL {$days} DAY)
      GROUP BY l.user_id,u.display_name,u.email
      ORDER BY tokens DESC,requests DESC LIMIT {$limit}")->fetchAll()?:[];
}

function subscription_intelligence_ai_daily(int $days=30,?PDO $pdo=null): array
{
    $pdo??=db();if(!$pdo||!subscription_intelligence_ready($pdo))return [];$days=max(7,min(180,$days));
    return $pdo->query("SELECT DATE(created_at) usage_date,COUNT(*) requests,COALESCE(SUM(total_tokens),0) tokens
      FROM ai_usage_ledger WHERE created_at>=DATE_SUB(CURDATE(),INTERVAL {$days} DAY)
      GROUP BY DATE(created_at) ORDER BY usage_date")->fetchAll()?:[];
}

function subscription_intelligence_credit_sources(int $days=30,?PDO $pdo=null): array
{
    $pdo??=db();if(!$pdo||!subscription_intelligence_ready($pdo))return [];$days=max(1,min(365,$days));
    return $pdo->query("SELECT source,COUNT(*) credits,COALESCE(SUM(amount),0) granted,
      COALESCE(SUM(remaining_amount),0) remaining
      FROM ai_token_credits WHERE created_at>=DATE_SUB(NOW(),INTERVAL {$days} DAY)
      GROUP BY source ORDER BY granted DESC,credits DESC")->fetchAll()?:[];
}

function subscription_intelligence_run_rate_by_package(?PDO $pdo=null): array
{
    $pdo??=db();if(!$pdo||!subscription_intelligence_ready($pdo))return [];
    return $pdo->query("SELECT COALESCE(p.name,'Unmapped') package_name,
      COUNT(DISTINCT bs.user_id) paid_accounts,
      COALESCE(SUM(CASE WHEN bp.billing_interval='annual' THEN ROUND(bp.unit_amount_cents/12) ELSE bp.unit_amount_cents END),0) mrr_cents
      FROM billing_subscriptions bs
      LEFT JOIN subscription_packages p ON p.id=bs.package_id
      INNER JOIN package_billing_prices bp ON bp.provider=bs.provider AND bp.provider_price_id=bs.provider_price_id
      WHERE bs.provider='stripe' AND bs.status='active'
      GROUP BY p.id,p.name ORDER BY mrr_cents DESC,paid_accounts DESC")->fetchAll()?:[];
}
