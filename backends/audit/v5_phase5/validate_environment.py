from importlib.metadata import version, PackageNotFoundError
PACKAGES=('numpy','pandas','scipy','scikit-learn','statsmodels','chronos-forecasting','torch','transformers')
for name in PACKAGES:
    try: print(f'{name}=={version(name)}')
    except PackageNotFoundError: print(f'{name}==UNAVAILABLE')
