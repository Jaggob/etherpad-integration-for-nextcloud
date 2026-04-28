<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Controller;

use OCA\EtherpadNextcloud\Exception\BindingException;
use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Exception\PadFileFormatException;
use OCA\EtherpadNextcloud\Service\PadCreateRollbackService;
use OCA\EtherpadNextcloud\Service\PadResponseService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\NotFoundException;
use OCP\Lock\LockedException;

/**
 * Centralizes mapping domain and framework exceptions to HTTP DataResponses.
 * Endpoints only provide wording per category; RuntimeException messages are
 * intentionally not exposed to clients to avoid leaking internal details.
 */
class PadControllerErrorMapper {
	public function __construct(
		private PadCreateRollbackService $rollbackService,
		private PadResponseService $padResponses,
	) {
	}

	/**
	 * @param callable(): mixed $action
	 * @param callable(mixed): DataResponse $success
	 * @param array{
	 *   invalid_argument?: string,
	 *   not_found?: string,
	 *   binding_message?: string,
	 *   binding_status?: int,
	 *   conflict_message?: string,
	 *   generic?: string,
	 *   map_throwable?: callable(\Throwable): ?DataResponse,
	 *   on_throwable?: callable(\Throwable): void
	 * } $options
	 */
	public function run(callable $action, callable $success, array $options = []): DataResponse {
		try {
			return $success($action());
		} catch (\InvalidArgumentException $e) {
			$configuredMessage = isset($options['invalid_argument'])
				? (string)$options['invalid_argument']
				: '';
			$exceptionMessage = $e->getMessage();
			$message = $configuredMessage !== ''
				? $configuredMessage
				: ($exceptionMessage !== '' ? $exceptionMessage : 'Invalid input.');

			return new DataResponse([
				'message' => $message,
			], Http::STATUS_BAD_REQUEST);
		} catch (NotFoundException) {
			return new DataResponse([
				'message' => (string)($options['not_found'] ?? 'Resource not found.'),
			], Http::STATUS_NOT_FOUND);
		} catch (LockedException) {
			return new DataResponse([
				'message' => 'Pad file is temporarily locked. Please retry.',
				'retryable' => true,
			], Http::STATUS_SERVICE_UNAVAILABLE);
		} catch (BindingException $e) {
			$message = isset($options['binding_message'])
				? (string)$options['binding_message']
				: $this->padResponses->bindingErrorMessage($e);
			return new DataResponse([
				'message' => $message,
			], (int)($options['binding_status'] ?? Http::STATUS_BAD_REQUEST));
		} catch (PadFileFormatException|EtherpadClientException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\RuntimeException $e) {
			$mapped = $this->mapThrowable($e, $options);
			if ($mapped instanceof DataResponse) {
				return $mapped;
			}
			if (isset($options['conflict_message']) && $this->rollbackService->isCreateConflict($e)) {
				return new DataResponse(['message' => (string)$options['conflict_message']], Http::STATUS_CONFLICT);
			}
			return $this->genericResponse($e, $options);
		} catch (\Throwable $e) {
			$mapped = $this->mapThrowable($e, $options);
			if ($mapped instanceof DataResponse) {
				return $mapped;
			}
			if (isset($options['conflict_message']) && $this->rollbackService->isCreateConflict($e)) {
				return new DataResponse(['message' => (string)$options['conflict_message']], Http::STATUS_CONFLICT);
			}
			return $this->genericResponse($e, $options);
		}
	}

	/** @param array<string,mixed> $options */
	private function mapThrowable(\Throwable $e, array $options): ?DataResponse {
		$mapper = $options['map_throwable'] ?? null;
		if (is_callable($mapper)) {
			$response = $mapper($e);
			if ($response instanceof DataResponse) {
				return $response;
			}
		}
		return null;
	}

	/** @param array<string,mixed> $options */
	private function genericResponse(\Throwable $e, array $options): DataResponse {
		$logger = $options['on_throwable'] ?? null;
		if (is_callable($logger)) {
			$logger($e);
		}
		return new DataResponse([
			'message' => (string)($options['generic'] ?? 'Request failed.'),
		], Http::STATUS_INTERNAL_SERVER_ERROR);
	}
}
