# Learning LMS — production architecture

Students reach the LMS at `http://lms.geslab.local` on port 80. Windows Server DNS resolves that name to the Ubuntu VM's IP. Only the nginx proxy publishes port 80; MailHog's web UI is published on 8025 for administration. MariaDB and SMTP port 1025 stay internal to the Docker network.

```mermaid
flowchart LR
    S[40 student PCs<br/>http://lms.geslab.local] -->|HTTP :80| P

    subgraph VM[Ubuntu Server 24.04 VM - 192.168.10.196]
        P[nginx proxy :80] -->|/| F[frontend nginx]
        P -->|/api/ and /uploads/| A[PHP API Apache :80]
        A -->|db:3306 internal| D[(MariaDB 10.11.19)]
        A -->|MAIL_HOST=mailhog :1025| M[(MailHog<br/>captured email)]
    end

    Admin[Administrator PC] -->|http://192.168.10.196:8025| M

    subgraph HOST[Windows Server 2025 - 32 GB RAM]
        H[Hyper-V + RDS 40 users] --> VM
        U[(External USB drive<br/>LearningBackups$ SMB)] -.2-hourly + final school-day copy.-> D
    end

    DNS[Windows Server DNS<br/>lms.geslab.local -> 192.168.10.196] -.resolves for students.-> S

    classDef net fill:#e8f4ff,stroke:#0366d6;
    classDef data fill:#fff4e5,stroke:#d97706;
    class S,P,F,A net;
    class D,U data;
```

## Port exposure

| Port | Service | Reachable from |
| --- | --- | --- |
| 80 | nginx proxy | All 40 student PCs |
| 8025 | MailHog web UI | Administrators only (LAN) |
| 1025 | MailHog SMTP | Internal Docker network only |
| 3306 | MariaDB | Internal Docker network only |
| 8081 / 8082 | API / frontend debug | `127.0.0.1` on the VM only |
