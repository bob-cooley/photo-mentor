"""Tests for the shared retry loop and the Twelve Data call spacing.

Run from this directory:  python3 -m unittest test_common -v
"""
from __future__ import annotations

import unittest
from unittest import mock

import requests

import common
import market


def _resp(status: int, headers: dict | None = None) -> requests.Response:
    r = requests.Response()
    r.status_code = status
    r.headers.update(headers or {})
    r._content = b"{}"
    r.url = "https://example.test/x"
    return r


class RetryTests(unittest.TestCase):
    def test_429_waits_then_succeeds(self):
        responses = [_resp(429), _resp(429), _resp(200)]
        with mock.patch("common.requests.request", side_effect=responses), mock.patch("common.time.sleep") as sleep:
            out = common.get("https://example.test/x")
        self.assertEqual(out.status_code, 200)
        self.assertEqual([c.args[0] for c in sleep.call_args_list], [20, 40])

    def test_429_honors_retry_after_and_caps_it(self):
        responses = [_resp(429, {"Retry-After": "30"}), _resp(429, {"Retry-After": "9999"}), _resp(200)]
        with mock.patch("common.requests.request", side_effect=responses), mock.patch("common.time.sleep") as sleep:
            common.get("https://example.test/x")
        self.assertEqual([c.args[0] for c in sleep.call_args_list], [30, common.RATE_LIMIT_MAX_WAIT_SECONDS])

    def test_429_gives_up_after_limit(self):
        responses = [_resp(429)] * (common.RATE_LIMIT_RETRIES + 1)
        with mock.patch("common.requests.request", side_effect=responses) as req, mock.patch("common.time.sleep"):
            with self.assertRaises(requests.exceptions.HTTPError):
                common.get("https://example.test/x")
        self.assertEqual(req.call_count, common.RATE_LIMIT_RETRIES + 1)

    def test_5xx_still_uses_short_backoff(self):
        responses = [_resp(503), _resp(200)]
        with mock.patch("common.requests.request", side_effect=responses), mock.patch("common.time.sleep") as sleep:
            common.get("https://example.test/x")
        self.assertEqual([c.args[0] for c in sleep.call_args_list], [common.RETRY_BACKOFF_SECONDS])

    def test_403_fails_immediately(self):
        with mock.patch("common.requests.request", return_value=_resp(403)) as req, mock.patch("common.time.sleep"):
            with self.assertRaises(requests.exceptions.HTTPError):
                common.get("https://example.test/x")
        self.assertEqual(req.call_count, 1)


class SpacingTests(unittest.TestCase):
    def test_consecutive_twelvedata_calls_are_spaced(self):
        market._last_call_at = 0.0
        clock = [1000.0]
        slept = []

        def fake_sleep(s):
            slept.append(s)
            clock[0] += s

        with mock.patch("market.time.monotonic", side_effect=lambda: clock[0]), \
             mock.patch("market.time.sleep", side_effect=fake_sleep), \
             mock.patch("market.get", return_value=_resp(200)):
            market._td_get("https://example.test/a")  # first call: no wait needed
            clock[0] += 1.0
            market._td_get("https://example.test/b")  # 1s later: must wait the remainder
        self.assertEqual(len(slept), 1)
        self.assertAlmostEqual(slept[0], market.MIN_SECONDS_BETWEEN_CALLS - 1.0)


if __name__ == "__main__":
    unittest.main()
