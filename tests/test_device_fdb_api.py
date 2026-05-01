"""
Test script for verifying the get_device_fdb API endpoint.

Route: /api/v0/devices/:hostname/fdb
- hostname can be either the device hostname or id
- vlan_id: Filter results by VLAN ID(s). Can be a single VLAN (vlan_id=100)
  or multiple VLANs as comma-separated values (vlan_id=100,200,300)
"""

import argparse
import json
import os
import ssl
import sys
from urllib.request import Request, urlopen
from urllib.error import HTTPError, URLError

API_BASE_URL = os.environ.get("LIBRENMS_API_URL", "https://10.248.0.104")
API_TOKEN = os.environ.get("LIBRENMS_API_TOKEN", "137355125b5cbe2253500e753e592c13")
DEFAULT_HOSTNAME = "localhost"
SKIP_SSL_VERIFY = False


def vlan_stats(response):
    vlans = {}

    for a in response["ports_fdb"]:
        if(a["vlan_id"] in vlans):
            vlans[a["vlan_id"]] += 1
        else:
            vlans[a["vlan_id"]] = 1

    print(f"Response: {json.dumps(vlans, indent=2)}")



def make_request(path, method="GET", data=None):
    """Make an API request and return the response."""
    url = f"{API_BASE_URL}{path}"
    headers = {
        "X-Auth-Token": API_TOKEN,
        "Content-Type": "application/json"
    }

    if data:
        data = json.dumps(data).encode("utf-8")
        headers["Content-Length"] = str(len(data))

    request = Request(url, data=data, headers=headers, method=method)
    try:
        if SKIP_SSL_VERIFY:
            ctx = ssl.create_default_context()
            ctx.check_hostname = False
            ctx.verify_mode = ssl.CERT_NONE
            with urlopen(request, context=ctx) as response:
                body = response.read().decode("utf-8")
                return response.status, json.loads(body)
        else:
            with urlopen(request) as response:
                body = response.read().decode("utf-8")
                return response.status, json.loads(body)
    except HTTPError as e:
        body = e.read().decode("utf-8")
        return e.code, {"error": body}
    except URLError as e:
        print(f"Connection error: {e.reason}")
        sys.exit(1)


def test_get_device_fdb_basic(hostname="localhost"):
    """Test basic FDB retrieval without VLAN filter."""
    print("\n=== Test: Get Device FDB (Basic) ===")
    status, response = make_request(f"/api/v0/devices/{hostname}/fdb")

    print(f"Status: {status}")
    #print(f"Response: {json.dumps(response, indent=2)}")

    assert status == 200, f"Expected status 200, got {status}"
    assert response.get("status") == "ok", f"Expected status 'ok', got {response.get('status')}"
    assert "ports_fdb" in response, "Expected 'ports_fdb' in response"

    test_response_structure(response)
    vlan_stats(response)
    print("PASSED: Basic FDB retrieval works")
    return response


def test_get_device_fdb_with_single_vlan(hostname="localhost", vlan_id=46):
    """Test FDB retrieval with single VLAN filter."""
    print(f"\n=== Test: Get Device FDB with Single VLAN (vlan_id={vlan_id}) ===")
    status, response = make_request(f"/api/v0/devices/{hostname}/fdb?vlan_id={vlan_id}")

    print(f"Status: {status}")

    assert status == 200, f"Expected status 200, got {status}"
    assert response.get("status") == "ok", f"Expected status 'ok', got {response.get('status')}"
    assert "ports_fdb" in response, "Expected 'ports_fdb' in response"


    test_response_structure(response)
    vlan_stats(response)

    print("PASSED: Single VLAN filter works")
    return response


def test_get_device_fdb_with_multiple_vlans(hostname="localhost", vlan_ids="34,35"):
    """Test FDB retrieval with multiple VLAN filters."""
    print(f"\n=== Test: Get Device FDB with Multiple VLANs (vlan_id={vlan_ids}) ===")
    status, response = make_request(f"/api/v0/devices/{hostname}/fdb?vlan_id={vlan_ids}")

    print(f"Status: {status}")
    assert status == 200, f"Expected status 200, got {status}"
    assert response.get("status") == "ok", f"Expected status 'ok', got {response.get('status')}"
    assert "ports_fdb" in response, "Expected 'ports_fdb' in response"

    test_response_structure(response)
    vlan_stats(response)

    print("PASSED: Multiple VLAN filter works")
    return response


def test_get_device_fdb_by_device_id(device_id=1):
    """Test FDB retrieval using device ID instead of hostname."""
    print(f"\n=== Test: Get Device FDB by Device ID (id={device_id}) ===")
    status, response = make_request(f"/api/v0/devices/{device_id}/fdb")

    print(f"Status: {status}")

    assert status == 200, f"Expected status 200, got {status}"
    assert response.get("status") == "ok", f"Expected status 'ok', got {response.get('status')}"
    assert "ports_fdb" in response, "Expected 'ports_fdb' in response"

    vlan_stats(response)

    print("PASSED: Device ID lookup works")
    return response


def test_get_device_fdb_not_found(hostname="nonexistent_device"):
    """Test FDB retrieval for non-existent device."""
    print(f"\n=== Test: Get Device FDB for Non-existent Device ({hostname}) ===")
    status, response = make_request(f"/api/v0/devices/{hostname}/fdb")

    print(f"Status: {status}")
    print(f"Response: {json.dumps(response, indent=2)}")

    assert status == 404, f"Expected status 404, got {status}"

    print("PASSED: Non-existent device returns 404")
    return response


def test_get_device_fdb_with_age(hostname="localhost", age=60):
    """Test FDB retrieval with age filter."""
    print(f"\n=== Test: Get Device FDB with Age Filter (age={age} minutes) ===")
    status, response = make_request(f"/api/v0/devices/{hostname}/fdb?age={age}")

    print(f"Status: {status}")

    assert status == 200, f"Expected status 200, got {status}"
    assert response.get("status") == "ok", f"Expected status 'ok', got {response.get('status')}"
    assert "ports_fdb" in response, "Expected 'ports_fdb' in response"

    test_response_structure(response)
    vlan_stats(response)

    print("PASSED: Age filter works")
    return response


def test_response_structure(response):
    """Verify the response structure matches the API documentation."""
    print("\n=== Test: Verify Response Structure ===")

    ports_fdb = response.get("ports_fdb")

    if isinstance(ports_fdb, dict):
        expected_fields = [
            "ports_fdb_id",
            "port_id",
            "mac_address",
            "vlan_id",
            "device_id",
            "created_at",
            "updated_at"
        ]
        for field in expected_fields:
            assert field in ports_fdb, f"Expected field '{field}' in ports_fdb"
        print(f"PASSED: All expected fields present in single FDB entry")
    elif isinstance(ports_fdb, list):
        if len(ports_fdb) > 0:
            expected_fields = [
                "ports_fdb_id",
                "port_id",
                "mac_address",
                "vlan_id",
                "device_id",
                "created_at",
                "updated_at"
            ]
            for field in expected_fields:
                assert field in ports_fdb[0], f"Expected field '{field}' in ports_fdb entry"
            print(f"PASSED: All expected fields present in FDB entries (count: {len(ports_fdb)})")
        else:
            print("INFO: Empty FDB list returned")
    else:
        print(f"INFO: ports_fdb is {type(ports_fdb)}: {ports_fdb}")

    return True


def main():
    """Run all tests."""
    global SKIP_SSL_VERIFY

    parser = argparse.ArgumentParser(description="Test get_device_fdb API endpoint")
    parser.add_argument("-d", "--device", default=DEFAULT_HOSTNAME, help="Device hostname or ID to test")
    parser.add_argument("-k", "--insecure", action="store_true", help="Skip SSL certificate verification")
    args = parser.parse_args()

    hostname = args.device
    SKIP_SSL_VERIFY = args.insecure

    print(f"Testing get_device_fdb API endpoint")
    print(f"Device: {hostname}")
    print(f"API URL: {API_BASE_URL}")
    print(f"API Token: {'*' * len(API_TOKEN) if len(API_TOKEN) > 4 else API_TOKEN}")

    tests_passed = 0
    tests_failed = 0

    try:
        test_get_device_fdb_basic(hostname)
        tests_passed += 1
    except AssertionError as e:
        print(f"FAILED: {e}")
        tests_failed += 1

    try:
        test_get_device_fdb_with_single_vlan(hostname)
        tests_passed += 1
    except AssertionError as e:
        print(f"FAILED: {e}")
        tests_failed += 1

    try:
        test_get_device_fdb_with_multiple_vlans(hostname)
        tests_passed += 1
    except AssertionError as e:
        print(f"FAILED: {e}")
        tests_failed += 1

    try:
        test_get_device_fdb_by_device_id()
        tests_passed += 1
    except AssertionError as e:
        print(f"FAILED: {e}")
        tests_failed += 1

    try:
        test_get_device_fdb_not_found()
        tests_passed += 1
    except AssertionError as e:
        print(f"FAILED: {e}")
        tests_failed += 1

    try:
        test_get_device_fdb_with_age(hostname)
        tests_passed += 1
    except AssertionError as e:
        print(f"FAILED: {e}")
        tests_failed += 1

    print("\n" + "=" * 50)
    print(f"Tests passed: {tests_passed}")
    print(f"Tests failed: {tests_failed}")

    if tests_failed > 0:
        sys.exit(1)
    else:
        print("\nAll tests passed!")
        sys.exit(0)


if __name__ == "__main__":
    main()