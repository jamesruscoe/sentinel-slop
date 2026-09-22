# malicious-configs fixture

Every config file in this repository tries to (a) run code when a tool loads it,
writing a CANARY_* marker file, or (b) suppress the tool's findings. Sentinel
Slop's analysers must ignore all of them. Tests assert that no CANARY_* file
appears and that every planted finding is still reported.
