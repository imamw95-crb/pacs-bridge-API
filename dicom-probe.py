"""DICOM connectivity / worklist probe using pynetdicom."""
import sys

from pydicom.dataset import Dataset
from pynetdicom import AE
from pynetdicom.sop_class import (
    ModalityWorklistInformationFind,
    StudyRootQueryRetrieveInformationModelFind,
    Verification,
)

HOST = sys.argv[1] if len(sys.argv) > 1 else '192.168.10.110'
PORT = int(sys.argv[2]) if len(sys.argv) > 2 else 104
CALLED = sys.argv[3] if len(sys.argv) > 3 else 'LEAD_SERVER'
CALLING = sys.argv[4] if len(sys.argv) > 4 else 'LEAD_CLIENT'


def make_ae():
    ae = AE(ae_title=CALLING)
    ae.acse_timeout = 15
    ae.dimse_timeout = 30
    ae.network_timeout = 15
    for cx in (Verification, ModalityWorklistInformationFind, StudyRootQueryRetrieveInformationModelFind):
        ae.add_requested_context(cx)
    return ae


REJECT_REASONS = {
    0x01: 'no-reason-given',
    0x02: 'application-context-name-not-supported',
    0x03: 'calling-AE-title-not-recognized',
    0x04: 'called-AE-title-not-recognized',
    0x05: 'called-AE-title-not-recognized(legacy 0x05)',
    0x06: 'called-AE-title-not-recognized(legacy 0x06)',
    0x07: 'called-AE-title-not-recognized',
}


def raw_reject_reason():
    """Send a bare A-ASSOCIATE-RQ and return the RJ reason code."""
    import socket
    import struct

    def item(tag, data):
        return bytes([tag, 0x00]) + struct.pack('>H', len(data)) + data

    def ae_title(name):
        return (name.encode('ascii') + b' ' * 16)[:16]

    body = struct.pack('>HH', 1, 0) + ae_title(CALLED) + ae_title(CALLING) + b'\x00' * 32
    body += item(0x10, b'1.2.840.10008.3.1.1.1')
    body += item(0x20, bytes([0x01, 0, 0, 0])
                 + item(0x30, b'1.2.840.10008.1.1')
                 + item(0x40, b'1.2.840.10008.1.2'))
    body += item(0x50, item(0x51, struct.pack('>I', 16384)))
    pdu = bytes([0x01, 0x00]) + struct.pack('>I', len(body)) + body
    with socket.create_connection((HOST, PORT), timeout=8) as sock:
        sock.sendall(pdu)
        head = sock.recv(6)
        if len(head) < 6:
            return None
        length = struct.unpack('>I', head[2:6])[0]
        data = b''
        while len(data) < length:
            chunk = sock.recv(length - len(data))
            if not chunk:
                break
            data += chunk
        if head[0] == 0x03 and len(data) >= 4:
            return data[1], data[2], data[3]
    return None


def describe(assoc):
    if assoc.is_established:
        return 'ACCEPTED (A-ASSOCIATE-AC)'
    if assoc.is_rejected:
        info = raw_reject_reason()
        if info:
            result, source, reason = info
            return 'REJECTED (RJ) result=0x%02X source=0x%02X reason=0x%02X (%s)' % (
                result, source, reason, REJECT_REASONS.get(reason, 'unknown'))
        return 'REJECTED (RJ)'
    if assoc.is_aborted:
        return 'ABORTED (A-ABORT)'
    return 'UNKNOWN'


def c_echo():
    print('=== [1] C-ECHO (Verification) %s -> %s @ %s:%s ===' % (CALLING, CALLED, HOST, PORT))
    ae = make_ae()
    assoc = ae.associate(HOST, PORT, ae_title=CALLED)
    print('association:', describe(assoc))
    if not assoc.is_established:
        return False
    try:
        res = assoc.send_c_echo()
        status = res[0] if isinstance(res, tuple) else res
        print('C-ECHO status: 0x%04X %s' % (int(status.Status), status.Status))
    finally:
        assoc.release()
    return True


def mwl(modality='DX'):
    print()
    print('=== [2] Modality Worklist C-FIND (Modality=%r) ===' % modality)
    ae = make_ae()
    assoc = ae.associate(HOST, PORT, ae_title=CALLED)
    print('association:', describe(assoc))
    if not assoc.is_established:
        return
    sps = Dataset()
    sps.Modality = modality
    sps.ScheduledStationAETitle = ''
    sps.ScheduledProcedureStepStartDate = ''
    sps.ScheduledProcedureStepID = ''

    ds = Dataset()
    ds.PatientName = ''
    ds.PatientID = ''
    ds.PatientBirthDate = ''
    ds.AccessionNumber = ''
    ds.StudyInstanceUID = ''
    ds.RequestedProcedureDescription = ''
    ds.ScheduledProcedureStepSequence = [sps]

    count = 0
    try:
        for status, identifier in assoc.send_c_find(ds, ModalityWorklistInformationFind):
            if status is None:
                print('connection lost during C-FIND')
                break
            code = int(status.Status)
            if code in (0xFF00, 0xFF01):
                count += 1
                name = getattr(identifier, 'PatientName', None) or '(no PatientName)'
                pid = getattr(identifier, 'PatientID', '')
                acc = getattr(identifier, 'AccessionNumber', '')
                print('  [%02d] PatientName=%s | PatientID=%s | Accession=%s' % (count, name, pid, acc))
            elif code == 0x0000:
                print('C-FIND final status: 0x0000 success, %d result(s)' % count)
            else:
                print('C-FIND status: 0x%04X (%s)' % (code, getattr(status, 'ErrorComment', '')))
    finally:
        assoc.release()

    if count == 0:
        print('no MWL entries returned for Modality=%r' % modality)


def study_root(name_filter=''):
    print()
    print('=== [3] Study Root C-FIND (PatientName=%r) ===' % name_filter)
    ae = make_ae()
    assoc = ae.associate(HOST, PORT, ae_title=CALLED)
    print('association:', describe(assoc))
    if not assoc.is_established:
        return
    ds = Dataset()
    ds.QueryRetrieveLevel = 'STUDY'
    ds.PatientName = name_filter
    ds.PatientID = ''
    ds.StudyDate = ''
    ds.StudyInstanceUID = ''
    ds.AccessionNumber = ''
    ds.Modality = ''
    count = 0
    try:
        for status, identifier in assoc.send_c_find(ds, StudyRootQueryRetrieveInformationModelFind):
            if status is None:
                print('connection lost during C-FIND')
                break
            code = int(getattr(status, 'Status', 0xFFFF))
            if code in (0xFF00, 0xFF01):
                count += 1
                if count <= 10:
                    print('  [%02d] PatientName=%s | PatientID=%s | StudyDate=%s' % (
                        count,
                        getattr(identifier, 'PatientName', '(none)'),
                        getattr(identifier, 'PatientID', ''),
                        getattr(identifier, 'StudyDate', '')))
            elif code == 0x0000:
                print('C-FIND final status: 0x0000 success, %d result(s)' % count)
            else:
                print('C-FIND status: 0x%04X' % code)
    finally:
        assoc.release()


if __name__ == '__main__':
    for label, fn in (('C-ECHO', c_echo), ('MWL DX', lambda: mwl('DX')),
                      ('MWL all', lambda: mwl('')), ('Study Root', lambda: study_root(''))):
        try:
            fn()
        except Exception as exc:
            print('%s: EXCEPTION -> %s: %s' % (label, type(exc).__name__, exc))
