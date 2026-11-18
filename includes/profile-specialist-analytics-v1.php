<?php
declare(strict_types=1);

require_once __DIR__.'/agent-coordination-profile-v1.php';
require_once __DIR__.'/agent-radar-foundation.php';
require_once __DIR__.'/vp3-analytics.php';
require_once __DIR__.'/profile-revenue-intelligence-v180.php';

/** One aggregate snapshot, with current private Profile authority after it closes. */
function vp3_profile_specialist_analytics_read_v1(PDO $pdo,array $scope,callable $read): array
{
    $binding=['owner_user_id'=>(int)($scope['user']['id']??0),
        'agent_id'=>$scope['agent_id']??null,'conversation_id'=>$scope['conversation_id']??null];
    $scope=vp3_coordination_profile_authority_v1($pdo,$scope,$binding);
    $owns=!$pdo->inTransaction();
    try{
        if($owns){
            if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->beginTransaction();
        }
        $data=$read($scope);
        if($owns)$pdo->commit();
        // Property ownership can change independently of the owner's Profile
        // capability. Do not return an aggregate under an obsolete selection.
        if(isset($data['properties'])){
            $identities=static function(array $rows):array{
                $out=[];foreach($rows as $row)$out[(int)$row['id']]=(string)$row['property_type'];ksort($out);return $out;
            };
            if($identities($data['properties'])!==$identities(vp3_analytics_property_rows($pdo,(int)$scope['user']['id'])))
                throw new RuntimeException('Analytics property access changed. Refresh the selected view.',503);
        }
    }catch(Throwable $error){
        if($owns&&$pdo->inTransaction())$pdo->rollBack();
        vp3_coordination_profile_authority_v1($pdo,$scope,$binding);
        if($error instanceof RuntimeException&&in_array($error->getCode(),[403,422],true))throw $error;
        throw new RuntimeException('Profile Analytics is temporarily unavailable. No totals were established.',503,$error);
    }
    // A repeatable-read transaction must not hide a permission change. The
    // endpoint also reopens the actual session before returning this snapshot.
    vp3_coordination_profile_authority_v1($pdo,$scope,$binding);
    return $data;
}

function vp3_profile_specialist_analytics_v1(PDO $pdo,array $scope,int $propertyId=0,int $days=30): array
{
    if($propertyId<0||$propertyId>9007199254740991||!in_array($days,[7,30,90],true))
        throw new RuntimeException('Choose an exact Analytics property and reporting period.',422);
    return vp3_profile_specialist_analytics_read_v1($pdo,$scope,static function(array $fresh)use($pdo,$propertyId,$days):array{
        // The portal's _v2 wrapper creates a native property. A workspace read
        // only reports existing storage and never initializes that identity.
        $data=vp3_analytics_dashboard_state($pdo,$fresh['user'],$propertyId,$days);
        if(($data['ready']??false)!==true||($data['coverage']??null)!=='complete'){
            if(isset($data['error']))throw new RuntimeException('Choose an available owned Analytics property.',422);
            throw new RuntimeException('Profile Analytics storage is unavailable.',503);
        }
        if(($data['property_id']??null)!==$propertyId||($data['days']??null)!==$days)
            throw new RuntimeException('The Analytics reporting selection changed.',503);
        $data['native_profile_coverage']=array_filter($data['properties'],static fn(array $row):bool=>($row['property_type']??null)==='native')?'ready':'unconfigured';
        return $data;
    });
}

function vp3_profile_specialist_revenue_v1(PDO $pdo,array $scope): array
{
    return vp3_profile_specialist_analytics_read_v1($pdo,$scope,static function(array $fresh)use($pdo):array{
        $data=profile_revenue_intelligence_v180($pdo,(int)$fresh['user']['id']);
        if(($data['ready']??false)!==true||($data['coverage']??null)!=='complete')
            throw new RuntimeException('Profile conversion intelligence is unavailable.',503);
        // These are the existing database-clock event/value summaries. Owner-
        // local briefing windows and verified payment outcomes have their own
        // later source adapters; this read does not relabel legacy measures.
        return $data;
    });
}
