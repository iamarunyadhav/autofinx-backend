import logging

from .config import Settings
from .http_service import serve


def main() -> None:
    settings = Settings.from_env()
    logging.basicConfig(level=getattr(logging, settings.log_level.upper(), logging.INFO), format="%(asctime)s %(levelname)s %(message)s")
    serve(settings)


if __name__ == "__main__":
    main()
