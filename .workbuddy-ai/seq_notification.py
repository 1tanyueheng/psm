"""Sequence diagram - Manage Alert and Notification.

The use case has a sender and a reader, and they never meet:

    1  the reminder command finds work due     -> one message per recipient
    2  the user opens the notification centre  -> reads, or changes preferences

Twelve messages. The old figure walked the dedupe ledger, the channel choice and
the wave loop message by message; those are properties of the sender, and the
reply says what was sent and to how many.

Grounded in `SendDeadlineReminders`, `NotificationDispatcher::sendReminder`,
`ReminderDispatch` (the ledger) and `NotificationController`.
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from seqkit import Sequence

OUT = r"C:\Users\yuehe\Desktop\study\fyp\AIProject\psm-system\thesis\diagrams\sequence_notification.png"

XS = [170, 520, 880, 1240, 1620, 1980]
HEADS = [
    "Scheduler",
    "SendDeadline\nReminders",
    "Notification\nDispatcher",
    "Notification\n(model + mail)",
    "User model\n+ preferences",
    "Reminder\nledger",
]

STEPS = [
    {"kind": "section", "label": "1. Scheduled reminder  ·  7, 3 and 1 days before a deadline"},

    {"a": 0, "b": 1, "kind": "call", "label": "1. Run psm:send-deadline-reminders"},
    {"a": 1, "b": 3, "kind": "call",
     "label": "2. Find the milestones due in 7, 3\nor 1 days"},
    {"a": 3, "b": 1, "kind": "return", "label": "3. milestones + students"},
    {"a": 1, "b": 2, "kind": "call", "label": "4. sendReminder(user, milestone)"},
    {"a": 2, "b": 5, "kind": "call", "label": "5. Has this wave been sent?"},
    {"a": 5, "b": 2, "kind": "return", "label": "6. no: send it"},
    {"a": 2, "b": 4, "kind": "call",
     "label": "7. Send the in-app record and the mail"},
    {"a": 2, "b": 5, "kind": "call", "label": "8. Record the dispatch"},
    {"a": 2, "b": 1, "kind": "return", "label": "9. sent"},
    {"a": 1, "b": 0, "kind": "return", "label": "10. Summary per wave"},

    {"kind": "section", "label": "2. Notification centre  ·  the reader"},

    {"a": 4, "b": 3, "kind": "call",
     "label": "11. The user reads, dismisses,\nor changes which types they receive"},
    {"a": 3, "b": 4, "kind": "return", "label": "12. Unread count and the latest five"},
]


if __name__ == "__main__":
    print("wrote", Sequence(XS, HEADS).draw(STEPS, OUT))
