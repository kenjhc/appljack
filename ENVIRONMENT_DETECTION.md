# 🎯 Environment Auto-Detection

## ✅ FIXED: Now Correctly Detects All 3 Environments

Both `applpass.php` and `cpa-event.php` now properly detect:

1. **Local Development** (localhost, laragon)
2. **Dev Server** (http://dev.appljack.com/)
3. **Production Server** (https://appljack.com/)

---

## 🔍 Detection Logic

### Priority 1: Check HTTP_HOST (Domain Name)
```php
$httpHost = $_SERVER['HTTP_HOST'] ?? '';

if (strpos($httpHost, 'dev.appljack.com') !== false) {
    // Dev server detected
    $environment = 'DEV_SERVER';
    $basePath = "/chroot/home/appljack/appljack.com/html/dev/";
}
elseif (strpos($httpHost, 'appljack.com') !== false && strpos($httpHost, 'dev.') === false) {
    // Production server detected
    $environment = 'PRODUCTION';
    $basePath = "/chroot/home/appljack/appljack.com/html/admin/";
}
```

### Priority 2: Check File Path (Fallback)
```php
elseif (strpos($currentPath, '/chroot/') !== false) {
    // On server but couldn't detect domain - check path
    if (strpos($currentPath, "/dev/") !== false) {
        $environment = 'DEV_SERVER';
    } else {
        $environment = 'PRODUCTION';
    }
}
```

### Priority 3: Default to Local Dev
```php
else {
    // Local development (localhost, laragon, etc.)
    $environment = 'LOCAL_DEV';
    $basePath = __DIR__ . DIRECTORY_SEPARATOR;
}
```

---

## 📁 Where Files Are Written

### Local Development:
```
Domain: localhost, 127.0.0.1, etc.
Files:  d:\laragon\www\appljack\applpass_queue.json
        d:\laragon\www\appljack\applpass_cpa_queue.json
Logs:   d:\laragon\www\appljack\applpass7.log
        d:\laragon\www\appljack\applpass_cpa.log
```

### Dev Server (http://dev.appljack.com/):
```
Domain: dev.appljack.com
Files:  /chroot/home/appljack/appljack.com/html/dev/applpass_queue.json
        /chroot/home/appljack/appljack.com/html/dev/applpass_cpa_queue.json
Logs:   /chroot/home/appljack/appljack.com/html/dev/applpass7.log
        /chroot/home/appljack/appljack.com/html/dev/applpass_cpa.log
```

### Production (https://appljack.com/):
```
Domain: appljack.com
Files:  /chroot/home/appljack/appljack.com/html/admin/applpass_queue.json
        /chroot/home/appljack/appljack.com/html/admin/applpass_cpa_queue.json
Logs:   /chroot/home/appljack/appljack.com/html/admin/applpass7.log
        /chroot/home/appljack/appljack.com/html/admin/applpass_cpa.log
```

---

## 🧪 How To Verify

### Check The Logs
After an event fires, check the log file:

**Local:**
```bash
cat applpass7.log
cat applpass_cpa.log
```

**Dev Server:**
```bash
cat /chroot/home/appljack/appljack.com/html/dev/applpass7.log
cat /chroot/home/appljack/appljack.com/html/dev/applpass_cpa.log
```

**Production:**
```bash
cat /chroot/home/appljack/appljack.com/html/admin/applpass7.log
cat /chroot/home/appljack/appljack.com/html/admin/applpass_cpa.log
```

### What You'll See:
```
Script started...
Environment: LOCAL_DEV (or DEV_SERVER or PRODUCTION)
Domain: localhost (or dev.appljack.com or appljack.com)
Base path: /path/to/files/
custid: 9706023615
feedid: testfeed
...
Successfully wrote event data to file: /path/to/applpass_queue.json
```

---

## ✅ Test Scenarios

### Scenario 1: Local Development
- **URL:** `http://localhost/appljack/applpass.php?c=...`
- **Detected Environment:** LOCAL_DEV
- **Queue File:** `d:\laragon\www\appljack\applpass_queue.json`
- **Status:** ✅ Will write to local directory

### Scenario 2: Dev Server Testing
- **URL:** `http://dev.appljack.com/applpass.php?c=...`
- **Detected Environment:** DEV_SERVER
- **Queue File:** `/chroot/.../html/dev/applpass_queue.json`
- **Status:** ✅ Will write to dev folder on server

### Scenario 3: Production
- **URL:** `https://appljack.com/applpass.php?c=...`
- **Detected Environment:** PRODUCTION
- **Queue File:** `/chroot/.../html/admin/applpass_queue.json`
- **Status:** ✅ Will write to admin folder on server

---

## 🎯 Key Differences From Before

### Before (BROKEN):
- ❌ Couldn't differentiate dev.appljack.com from appljack.com
- ❌ Both would write to same location
- ❌ Dev would write to production files

### Now (FIXED):
- ✅ Checks domain name first (HTTP_HOST)
- ✅ dev.appljack.com → /dev/ folder
- ✅ appljack.com → /admin/ folder
- ✅ localhost → current directory
- ✅ Each environment writes to its own files

---

## 📝 Summary

**Three environments. Three different locations. One codebase.**

The same code automatically detects which environment it's running in and writes files to the correct location. No code changes needed when deploying!

✅ **Deploy once, works everywhere!**
