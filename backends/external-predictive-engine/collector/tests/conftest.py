import sys
from pathlib import Path

# make `import collector` work no matter where pytest is started from
sys.path.insert(0, str(Path(__file__).resolve().parents[2]))
