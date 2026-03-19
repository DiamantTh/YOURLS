<?php

/**
 * Tests for login brute-force / rate-limiting protection
 *
 * @since 1.10.4
 */
#[\PHPUnit\Framework\Attributes\Group('auth')]
#[\PHPUnit\Framework\Attributes\Group('login')]
class LoginBruteForceTest extends PHPUnit\Framework\TestCase {

    /**
     * A fake IP address used throughout these tests, to avoid affecting real state
     */
    protected string $test_ip = '10.0.0.1';

    protected function setUp(): void {
        yourls_reset_login_attempts( $this->test_ip );
    }

    protected function tearDown(): void {
        yourls_reset_login_attempts( $this->test_ip );
        yourls_remove_all_filters( 'max_login_attempts' );
        yourls_remove_all_filters( 'login_lockout_duration' );
        yourls_remove_all_filters( 'shunt_check_login_attempts' );
    }

    /**
     * A fresh IP has no failed attempts and must be allowed
     */
    public function test_fresh_ip_is_allowed(): void {
        $this->assertTrue( yourls_check_login_attempts( $this->test_ip ) );
    }

    /**
     * Recording failures must store them and still allow login below the threshold
     */
    public function test_failed_logins_below_threshold_are_allowed(): void {
        yourls_add_filter( 'max_login_attempts', function() { return 3; } );

        yourls_record_failed_login( $this->test_ip );
        yourls_record_failed_login( $this->test_ip );

        $this->assertTrue( yourls_check_login_attempts( $this->test_ip ) );
    }

    /**
     * Reaching the threshold must lock out the IP
     */
    public function test_ip_is_locked_out_after_max_attempts(): void {
        yourls_add_filter( 'max_login_attempts', function() { return 3; } );

        yourls_record_failed_login( $this->test_ip );
        yourls_record_failed_login( $this->test_ip );
        yourls_record_failed_login( $this->test_ip );

        $this->assertFalse( yourls_check_login_attempts( $this->test_ip ) );
    }

    /**
     * Resetting attempts must immediately allow login again
     */
    public function test_reset_lifts_lockout(): void {
        yourls_add_filter( 'max_login_attempts', function() { return 2; } );

        yourls_record_failed_login( $this->test_ip );
        yourls_record_failed_login( $this->test_ip );
        $this->assertFalse( yourls_check_login_attempts( $this->test_ip ) );

        yourls_reset_login_attempts( $this->test_ip );
        $this->assertTrue( yourls_check_login_attempts( $this->test_ip ) );
    }

    /**
     * Setting max_login_attempts to 0 must disable the lockout entirely
     */
    public function test_zero_max_attempts_disables_lockout(): void {
        yourls_add_filter( 'max_login_attempts', function() { return 0; } );

        for ( $i = 0; $i < 100; $i++ ) {
            yourls_record_failed_login( $this->test_ip );
        }

        $this->assertTrue( yourls_check_login_attempts( $this->test_ip ) );
    }

    /**
     * An expired lockout must automatically lift and allow login
     */
    public function test_expired_lockout_is_lifted(): void {
        yourls_add_filter( 'max_login_attempts',      function() { return 2; } );
        yourls_add_filter( 'login_lockout_duration',  function() { return -1; } ); // already expired

        yourls_record_failed_login( $this->test_ip );
        yourls_record_failed_login( $this->test_ip );

        // Even though the threshold was reached, the lockout duration is -1 (already in the past)
        $this->assertTrue( yourls_check_login_attempts( $this->test_ip ) );
    }

    /**
     * The shunt filter must be honoured
     */
    public function test_shunt_filter_overrides_check(): void {
        yourls_add_filter( 'max_login_attempts', function() { return 1; } );
        yourls_record_failed_login( $this->test_ip );

        // shunt returns false → locked out
        yourls_add_filter( 'shunt_check_login_attempts', 'yourls_return_false' );
        $this->assertFalse( yourls_check_login_attempts( $this->test_ip ) );
        yourls_remove_filter( 'shunt_check_login_attempts', 'yourls_return_false' );

        // shunt returns true → allowed even though threshold reached
        yourls_add_filter( 'shunt_check_login_attempts', 'yourls_return_true' );
        $this->assertTrue( yourls_check_login_attempts( $this->test_ip ) );
        yourls_remove_filter( 'shunt_check_login_attempts', 'yourls_return_true' );
    }

    /**
     * After a successful login the attempt counter must be cleared
     */
    public function test_successful_login_resets_counter(): void {
        yourls_add_filter( 'max_login_attempts', function() { return 5; } );

        yourls_record_failed_login( $this->test_ip );
        yourls_record_failed_login( $this->test_ip );

        yourls_reset_login_attempts( $this->test_ip );

        // Counter was wiped: still below threshold
        $this->assertTrue( yourls_check_login_attempts( $this->test_ip ) );
    }

    /**
     * The 'failed_login' action must fire when recording a failure
     */
    public function test_failed_login_action_fires(): void {
        $fired = 0;
        yourls_add_action( 'failed_login', function() use ( &$fired ) { $fired++; } );

        yourls_record_failed_login( $this->test_ip );

        $this->assertSame( 1, $fired );
        yourls_remove_all_actions( 'failed_login' );
    }

    /**
     * The 'login_locked_out' action must fire when an IP is rejected
     */
    public function test_login_locked_out_action_fires(): void {
        yourls_add_filter( 'max_login_attempts', function() { return 1; } );

        yourls_record_failed_login( $this->test_ip );

        $fired = 0;
        yourls_add_action( 'login_locked_out', function() use ( &$fired ) { $fired++; } );

        yourls_check_login_attempts( $this->test_ip );

        $this->assertSame( 1, $fired );
        yourls_remove_all_actions( 'login_locked_out' );
    }

}
