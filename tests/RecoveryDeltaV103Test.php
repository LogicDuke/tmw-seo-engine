<?php
/** PR-H v1.0.3 final correction tests. */
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Recovery\UnresolvedTransactionOutcomeConnection as Conn;
use TMWSEO\Engine\Recovery\UnresolvedTransactionOutcomeRepository as Repo;

if ( ! class_exists( 'wpdb', false ) ) {
    class wpdb {
        public static bool $connect_result = true;
        protected $dbuser; protected $dbpassword; protected $dbname; protected $dbhost; protected $reconnect_retries=5;
        public string $prefix = 'wp_'; public string $last_error=''; public int $last_errno=0;
        public $dbh = null; public bool $ready=false; public array $connect_allow_bail=[]; public array $check_allow_bail=[];
        public bool $parent_constructor_called = false;
        public string $driver_used = '';
        private bool $use_mysqli = false;

        public function __construct( $u, $p, $n, $h ) {
            // Model WordPress 6.3: the parent constructor initializes private
            // driver state, then invokes db_connect() using virtual dispatch.
            $this->use_mysqli = true;
            $this->dbuser = $u;
            $this->dbpassword = $p;
            $this->dbname = $n;
            $this->dbhost = $h;
            $this->parent_constructor_called = true;
            $this->db_connect();
        }

        public function db_connect( $allow_bail = true ) {
            $this->connect_allow_bail[] = $allow_bail;
            $this->driver_used = $this->use_mysqli ? 'mysqli' : 'mysql';
            if ( $allow_bail ) { throw new RuntimeException( 'bailing connect called' ); }
            if ( ! self::$connect_result ) { $this->dbh=null; $this->ready=false; return false; }
            $this->dbh='connected'; $this->ready=true; return true;
        }

        public function check_connection( $allow_bail = true ) { $this->check_allow_bail[]=$allow_bail; if($allow_bail){ throw new RuntimeException('bailing reconnect called'); } return false; }
        public function suppress_errors( bool $s=true ): bool { return true; }
        public function hide_errors(): bool { return true; }
        public function set_prefix( string $p ): string { $this->prefix=$p; return $p; }
        public function query( string $sql ) { return 0; }
        public function close(): bool { $this->dbh=null; return true; }
    }
}
if ( ! defined( 'DB_NAME' ) ) { define( 'DB_NAME', 'tmw_test_db' ); }
if ( ! defined( 'DB_USER' ) ) { define( 'DB_USER', 'tmw_test_user' ); }
if ( ! defined( 'DB_PASSWORD' ) ) { define( 'DB_PASSWORD', 'secret' ); }
if ( ! defined( 'DB_HOST' ) ) { define( 'DB_HOST', 'db.invalid' ); }

require_once __DIR__ . '/support/RecoveryFakeDb.php';
require_once __DIR__ . '/../includes/recovery/class-unresolved-transaction-outcome-connection.php';
require_once __DIR__ . '/../includes/recovery/class-unresolved-transaction-outcome-repository.php';


final class NonBailingRecoveryConnection extends Conn {
    private string $timeout = '60';
    protected function read_connect_timeout() { return $this->timeout; }
    protected function write_connect_timeout( string $value ) { $previous=$this->timeout; $this->timeout=$value; return $previous; }
}

final class TimeoutTrackingRecoveryConnection extends Conn {
    public array $values = [ '17' ];
    public array $writes = [];
    protected function read_connect_timeout() { return end($this->values); }
    protected function write_connect_timeout( string $value ) { $this->writes[]=$value; $previous=end($this->values); $this->values[]=$value; return $previous; }
    protected function create_wpdb() { return new class {
        public string $prefix='wp_'; public string $last_error=''; public $dbh='connected'; public string $error='';
        public function suppress_errors(bool $s=true):bool{return true;} public function hide_errors():bool{return true;}
        public function set_prefix(string $p):string{$this->prefix=$p;return $p;} public function query(string $q){return 0;} public function close():bool{return true;}
    }; }
}

final class RecoveryDeltaV103Test extends TestCase {
    protected function setUp(): void {
        set_error_handler(static function(int $no,string $msg,string $file='',int $line=0):bool{ if(0===(error_reporting()&$no))return false; throw new ErrorException($msg,0,$no,$file,$line); });
        $GLOBALS['wpdb']=new class { public string $prefix='wp_'; };
    }
    protected function tearDown(): void { restore_error_handler(); }

    private function outcome(array $o=[]): array { return array_merge([
        'operation_key'=>'manual_approval:row:900','operation_type'=>'manual_approval','row_id'=>900,'batch_id'=>70,
        'expected_candidate_id'=>10,'expected_assignment_key'=>'abc123','correlation_id'=>'corr-a','reason'=>'commit_unknown','evidence'=>['state'=>'open'],
    ],$o); }
    private function repo(?RecoveryFakeDb &$db=null): Repo { $store=RecoveryStore::fresh('v103-'.uniqid('',true)); $db=new RecoveryFakeDb($store); return new Repo(new RecoveryFakeConnectionFactory($db)); }

    public function test_real_factory_uses_non_bailing_connect_and_reconnect(): void {
        $conn=new NonBailingRecoveryConnection(); $result=$conn->open();
        $this->assertTrue((bool)$result['ok'],(string)$result['error']);
        $db=$result['db'];
        $this->assertTrue($db->parent_constructor_called, 'WordPress parent constructor must initialize private driver state');
        $this->assertSame('mysqli', $db->driver_used, 'WordPress 6.3-compatible recovery connections must use MySQLi');
        $this->assertSame([false],$db->connect_allow_bail);
        $this->assertFalse((bool)$db->check_connection(), 'reconnect must return false rather than bail');
        $this->assertSame([false],$db->check_allow_bail);
        $conn->close($db);
    }

    public function test_unavailable_independent_connection_returns_without_terminating_request(): void {
        wpdb::$connect_result=false;
        try {
            $conn=new NonBailingRecoveryConnection(); $result=$conn->open();
            $this->assertFalse((bool)$result['ok']);
            $this->assertSame('connection_failure',(string)$result['status']);
        } finally { wpdb::$connect_result=true; }
    }

    public function test_original_connect_timeout_is_captured_before_change_and_restored(): void {
        $conn=new TimeoutTrackingRecoveryConnection(); $result=$conn->open();
        $this->assertTrue((bool)$result['ok'],(string)$result['error']);
        $this->assertSame(['3','17'],$conn->writes,'must apply 3 then restore original 17');
    }

    public function test_empty_blocking_criteria_fail_closed(): void {
        $repo=$this->repo($db); $repo->record_unresolved_outcome($this->outcome());
        $r=$repo->has_blocking_outcome([]);
        $this->assertFalse((bool)$r['ok']); $this->assertTrue((bool)$r['blocking']); $this->assertSame('invalid_criteria',(string)$r['status']);
    }

    public function test_oversized_key_is_rejected_consistently_on_find_block_and_resolve(): void {
        $repo=$this->repo($db); $key=str_repeat('x',Repo::MAX_KEY_LENGTH+1);
        $find=$repo->find_unresolved_outcome($key); $block=$repo->has_blocking_outcome(['operation_key'=>$key]);
        $resolve=$repo->resolve_outcome($key,1,['decision'=>'acknowledged','resolved_by'=>1,'resolution_reason'=>'checked']);
        $this->assertSame('invalid_operation_key',(string)$find['status']);
        $this->assertSame('invalid_operation_key',(string)$block['status']); $this->assertTrue((bool)$block['blocking']);
        $this->assertSame('invalid_operation_key',(string)$resolve['status']);
    }

    public function test_concurrent_escalation_does_not_verify_another_writers_payload(): void {
        $repo=$this->repo($db); $first=$repo->record_unresolved_outcome($this->outcome()); $this->assertTrue((bool)$first['ok']);
        $db->post_write_row_override=[
            'operation_key'=>'manual_approval:row:900','operation_type'=>'manual_approval','row_id'=>900,'batch_id'=>70,
            'expected_candidate_id'=>99,'expected_assignment_key'=>'winner','correlation_id'=>'corr-winner','state'=>'unresolved',
            'reason'=>'another_writer','evidence'=>'{"winner":true}','generation'=>3,'resolved_at'=>null,'resolved_by'=>0,'resolution_reason'=>'',
        ];
        $second=$repo->record_unresolved_outcome($this->outcome(['reason'=>'my_reason','correlation_id'=>'corr-mine','evidence'=>['mine'=>true]]));
        $this->assertFalse((bool)$second['ok']);
        $this->assertContains((string)$second['status'],['superseded_after_write','verification_failure']);
    }

    public function test_exact_record_payload_is_verified(): void {
        $repo=$this->repo($db); $r=$repo->record_unresolved_outcome($this->outcome());
        $this->assertTrue((bool)$r['ok'],(string)$r['reason']); $this->assertSame('commit_unknown',(string)$r['row']['reason']);
        $this->assertSame('corr-a',(string)$r['row']['correlation_id']); $this->assertSame(10,(int)$r['row']['expected_candidate_id']);
    }

    public function test_release_identity_is_exact_v104(): void {
        $plugin=(string)file_get_contents(__DIR__.'/../tmw-seo-engine.php'); $changelog=(string)file_get_contents(__DIR__.'/../CHANGELOG.md');
        $version='5.9.29-recovery-outcomes-v1.0.4';
        $this->assertStringContainsString('Version: '.$version,$plugin);
        $this->assertStringContainsString("TMWSEO_ENGINE_VERSION', '".$version,$plugin);
        $this->assertStringContainsString('## '.$version,$changelog);
    }
}
