<?php
namespace ASS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bulkgate_Client {

	const PRIMARY_URL      = 'https://portal.bulkgate.com/api/2.0/advanced/transactional';
	const BACKUP_URL       = 'https://backup1.bulkgate.com/api/2.0/advanced/transactional';
	const TIMEOUT          = 20;
	const SUCCESS_STATUSES = [ 'accepted', 'scheduled', 'sent' ];

	private $application_id;
	private $application_token;
	private $sender;

	public function __construct( int $application_id, string $application_token, string $sender = '' ) {
		$this->application_id    = $application_id;
		$this->application_token = $application_token;
		$this->sender            = $sender;
	}

	public function send( string $number, string $text ): array {
		if ( $this->application_id <= 0 || '' === $this->application_token ) {
			return self::failure( 'not_configured', __( 'BulkGate credentials are not configured.', 'advanced-shipping-settings' ) );
		}

		if ( ! preg_match( '/^\d{8,15}$/', $number ) ) {
			return self::failure( 'invalid_number', __( 'Invalid SMS recipient number.', 'advanced-shipping-settings' ) );
		}

		if ( ! Sms_Text::is_sendable( $text ) || Sms_Text::plain( $text ) !== $text ) {
			return self::failure( 'invalid_text', __( 'The SMS text is empty, too long or contains unsupported characters.', 'advanced-shipping-settings' ) );
		}

		$sms_channel = [
			'sender_id' => '' !== $this->sender ? 'gText' : 'gSystem',
			'unicode'   => false,
		];
		if ( '' !== $this->sender ) {
			$sms_channel['sender_id_value'] = $this->sender;
		}

		$payload = [
			'application_id'    => $this->application_id,
			'application_token' => $this->application_token,
			'number'            => [ $number ],
			'text'              => $text,
			'duplicates_check'  => 'on',
			'channel'           => [ 'sms' => $sms_channel ],
		];

		$urls     = (array) apply_filters( 'ass_bulkgate_endpoints', [ self::PRIMARY_URL, self::BACKUP_URL ] );
		$response = $this->post( (string) reset( $urls ), $payload );

		if ( is_wp_error( $response ) ) {
			if ( ! self::is_connection_failure( $response ) ) {
				return self::failure( 'delivery_unknown', sprintf( __( 'BulkGate request failed, delivery unknown: %s', 'advanced-shipping-settings' ), $response->get_error_message() ) );
			}
			$backup = count( $urls ) > 1 ? (string) next( $urls ) : '';
			if ( '' === $backup ) {
				return self::failure( 'unreachable', __( 'BulkGate is unreachable.', 'advanced-shipping-settings' ) );
			}
			$response = $this->post( $backup, $payload );
			if ( is_wp_error( $response ) ) {
				return self::failure( 'unreachable', sprintf( __( 'BulkGate is unreachable: %s', 'advanced-shipping-settings' ), $response->get_error_message() ) );
			}
		}

		return self::parse_response( $number, (int) wp_remote_retrieve_response_code( $response ), (string) wp_remote_retrieve_body( $response ) );
	}

	private function post( string $url, array $payload ) {
		return wp_remote_post(
			$url,
			[
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'headers'     => [ 'Content-Type' => 'application/json' ],
				'body'        => wp_json_encode( $payload ),
			]
		);
	}

	private static function is_connection_failure( \WP_Error $error ): bool {
		$message = $error->get_error_message();
		if ( preg_match( '/cURL error (6|7|35)\b/', $message ) ) {
			return true;
		}
		return (bool) preg_match( '/cURL error 28\b.*(connect|resolv)/i', $message );
	}

	public static function parse_response( string $number, int $http_code, string $raw_body ): array {
		$body = json_decode( $raw_body, true );

		if ( ! is_array( $body ) ) {
			return self::failure( 'bad_response', sprintf( __( 'BulkGate returned an unreadable response (HTTP %d).', 'advanced-shipping-settings' ), $http_code ) );
		}

		if ( 200 !== $http_code || ! empty( $body['error'] ) ) {
			$type    = isset( $body['type'] ) && is_scalar( $body['type'] ) ? (string) $body['type'] : 'error';
			$message = isset( $body['error'] ) && is_scalar( $body['error'] ) ? (string) $body['error'] : 'HTTP ' . $http_code;
			return self::failure( sanitize_key( $type ), sprintf( 'BulkGate %s: %s', $type, $message ) );
		}

		$data    = isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : null;
		$entries = null;
		if ( null !== $data && isset( $data['response'] ) && is_array( $data['response'] ) ) {
			$entries = $data['response'];
		} elseif ( null !== $data && isset( $data['status'] ) ) {
			$entries = [ $data ];
		}

		if ( null === $entries ) {
			return self::failure( 'bad_response', sprintf( __( 'BulkGate response has no result: %s', 'advanced-shipping-settings' ), substr( $raw_body, 0, 300 ) ) );
		}

		$entry = null;
		foreach ( $entries as $candidate ) {
			if ( is_array( $candidate ) && preg_replace( '/\D+/', '', (string) ( $candidate['number'] ?? '' ) ) === $number ) {
				$entry = $candidate;
				break;
			}
		}
		if ( null === $entry && 1 === count( $entries ) && is_array( $entries[0] ) && ! isset( $entries[0]['number'] ) ) {
			$entry = $entries[0];
		}

		if ( null === $entry ) {
			return self::failure( 'missing', __( 'The number is missing from the BulkGate response.', 'advanced-shipping-settings' ) );
		}

		$status   = isset( $entry['status'] ) && is_scalar( $entry['status'] ) ? (string) $entry['status'] : 'unknown';
		$accepted = in_array( $status, self::SUCCESS_STATUSES, true );
		$part_ids = $entry['part_id'] ?? null;

		$message_id = '';
		foreach ( [ 'message_id', 'sms_id' ] as $key ) {
			if ( ! empty( $entry[ $key ] ) && is_scalar( $entry[ $key ] ) ) {
				$message_id = (string) $entry[ $key ];
				break;
			}
		}

		$error = '';
		if ( ! $accepted ) {
			$error = ! empty( $entry['error'] ) && is_scalar( $entry['error'] ) ? (string) $entry['error'] : $status;
		}

		return [
			'accepted'   => $accepted,
			'status'     => sanitize_key( $status ),
			'message_id' => sanitize_text_field( $message_id ),
			'parts'      => is_array( $part_ids ) && $part_ids ? count( $part_ids ) : ( $accepted ? 1 : 0 ),
			'error'      => sanitize_text_field( $error ),
		];
	}

	private static function failure( string $status, string $error ): array {
		return [
			'accepted'   => false,
			'status'     => $status,
			'message_id' => '',
			'parts'      => 0,
			'error'      => sanitize_text_field( $error ),
		];
	}
}
