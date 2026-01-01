import os
import re
import time
from pathlib import Path
from typing import List, Dict, Tuple, Optional
import logging

# Configure logging
logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(levelname)s - %(message)s')
logger = logging.getLogger(__name__)

class TestFileCreator:
    """Handles test file creation and management."""
    
    @staticmethod
    def get_test_directory(php_file_path: str) -> Optional[Path]:
        """
        Determine the appropriate test directory based on the PHP file location.
        
        Args:
            php_file_path: Path to the PHP source file
            
        Returns:
            Path object for the test directory or None if cannot determine
        """
        try:
            php_path = Path(php_file_path)
            relative_path = php_path.relative_to('app')
            
            # Map source directories to test directories
            if 'Http/Controllers' in str(relative_path) or 'Http' in str(relative_path):
                return Path('tests/Feature')
            elif 'Models' in str(relative_path) or 'Services' in str(relative_path):
                return Path('tests/Unit')
            else:
                # Default to Unit tests for other files
                return Path('tests/Unit')
        except ValueError:
            logger.warning(f"Cannot determine test directory for {php_file_path}")
            return None
    
    @staticmethod
    def create_test_file_stub(php_file_path: str, methods: List[str]) -> Optional[str]:
        """
        Create a test file stub with basic structure.
        
        Args:
            php_file_path: Path to the PHP source file
            methods: List of public method names
            
        Returns:
            Path to created test file or None if creation failed
        """
        try:
            test_dir = TestFileCreator.get_test_directory(php_file_path)
            if not test_dir:
                return None
            
            # Create test directory if it doesn't exist
            test_dir.mkdir(parents=True, exist_ok=True)
            
            # Generate test filename
            php_path = Path(php_file_path)
            test_filename = php_path.stem + 'Test.php'
            test_file_path = test_dir / test_filename
            
            # Check if test file already exists
            if test_file_path.exists():
                logger.info(f"Test file already exists: {test_file_path}")
                return str(test_file_path)
            
            # Generate test class name
            test_class_name = php_path.stem + 'Test'
            
            # Generate test method stubs
            test_methods = []
            for method in methods:
                test_method_name = f"test_{method}"
                test_methods.append(f"""
    /** @test */
    public function {test_method_name}()
    {{
        // TODO: Implement test for {method}()
        $this->assertTrue(true);
    }}""")
            
            # Create test file content
            # Note: Using double backslashes for namespace separator
            namespace = "Feature" if "Feature" in str(test_dir) else "Unit"
            test_content = f"""<?php

namespace Tests\\{namespace};

use Tests\\TestCase;

class {test_class_name} extends TestCase
{{{''.join(test_methods)}
}}
"""
            
            # Write test file
            test_file_path.write_text(test_content, encoding='utf-8')
            logger.info(f"Created test file: {test_file_path}")
            
            return str(test_file_path)
            
        except Exception as e:
            logger.error(f"Failed to create test file for {php_file_path}: {e}")
            return None


class PHPFileAnalyzer:
    """Analyzes PHP files to extract public methods."""
    
    @staticmethod
    def is_test_file(filename: str) -> bool:
        """Check if a file is a test file."""
        filename_lower = filename.lower()
        return (filename.endswith('Test.php') or 
                'test' in filename_lower or 
                'tests' in filename_lower)
    
    @staticmethod
    def list_php_files(directory: str) -> List[str]:
        """
        Recursively list all PHP files in a directory, excluding test files.
        
        Args:
            directory: Root directory to scan
            
        Returns:
            List of PHP file paths
        """
        php_files = []
        directory_path = Path(directory)
        
        if not directory_path.exists():
            logger.error(f"Directory does not exist: {directory}")
            return php_files
        
        for file_path in directory_path.rglob('*.php'):
            # Skip test files and directories containing 'test'
            if ('test' in str(file_path).lower() or 
                PHPFileAnalyzer.is_test_file(file_path.name)):
                continue
            php_files.append(str(file_path))
        
        return php_files
    
    @staticmethod
    def extract_public_methods(file_path: str) -> List[str]:
        """
        Extract public method names from a PHP file.
        
        Args:
            file_path: Path to the PHP file
            
        Returns:
            List of public method names (excluding constructors and magic methods)
        """
        methods = []
        
        try:
            with open(file_path, 'r', encoding='utf-8') as f:
                content = f.read()
            
            # Remove comments to avoid false positives
            content_no_comments = re.sub(
                r'//.*?$|/\*.*?\*/|\#.*?$',
                '',
                content,
                flags=re.MULTILINE | re.DOTALL
            )
            
            # Find class definition
            class_match = re.search(r'class\s+(\w+)', content_no_comments)
            if not class_match:
                return methods
            
            # Find all public methods
            method_pattern = re.compile(
                r'public\s+(?:static\s+)?function\s+(\w+)\s*\([^)]*\)'
            )
            
            for match in method_pattern.finditer(content_no_comments):
                method_name = match.group(1)
                
                # Skip constructors, destructors, and magic methods
                if (method_name.startswith('__') or 
                    method_name in ['__construct', '__destruct'] or
                    method_name.lower() == class_match.group(1).lower()):
                    continue
                
                methods.append(method_name)
                
        except Exception as e:
            logger.error(f"Error reading file {file_path}: {e}")
        
        return methods


class PublicMethodsAnalyzer:
    """Main analyzer class that coordinates the analysis process."""
    
    def __init__(self, src_dir: str = 'app'):
        self.src_dir = src_dir
        self.php_files = []
        self.all_methods = {}
        self.created_test_files = []
    
    def analyze(self) -> Dict[str, List[str]]:
        """Perform the analysis of PHP files."""
        logger.info(f"Starting analysis of {self.src_dir}")
        
        # Get PHP files
        self.php_files = PHPFileAnalyzer.list_php_files(self.src_dir)
        logger.info(f"Found {len(self.php_files)} PHP files to analyze")
        
        # Extract methods from each file
        start_time = time.perf_counter()
        
        for php_file in self.php_files:
            methods = PHPFileAnalyzer.extract_public_methods(php_file)
            if methods:
                self.all_methods[php_file] = methods
        
        end_time = time.perf_counter()
        self.duration_ms = (end_time - start_time) * 1000
        
        return self.all_methods
    
    def create_test_files(self) -> List[str]:
        """Create test files for files with public methods."""
        logger.info("Creating test files...")
        
        for php_file, methods in self.all_methods.items():
            test_file = TestFileCreator.create_test_file_stub(php_file, methods)
            if test_file:
                self.created_test_files.append(test_file)
        
        logger.info(f"Created {len(self.created_test_files)} test files")
        return self.created_test_files
    
    def get_statistics(self) -> Dict[str, int | float]:
        """Get analysis statistics."""
        return {
            'total_files': len(self.php_files),
            'files_with_methods': len(self.all_methods),
            'total_methods': sum(len(methods) for methods in self.all_methods.values()),
            'created_test_files': len(self.created_test_files),
            'duration_ms': self.duration_ms
        }
    
    def print_report(self):
        """Print analysis report to console."""
        stats = self.get_statistics()
        
        print(f"\n{'='*50}")
        print("PUBLIC METHODS ANALYSIS REPORT")
        print(f"{'='*50}")
        print(f"Execution time: {stats['duration_ms']:.2f} ms")
        print(f"Files scanned: {stats['total_files']}")
        print(f"Files with public methods: {stats['files_with_methods']}")
        print(f"Total public methods: {stats['total_methods']}")
        print(f"Test files created: {stats['created_test_files']}")
        
        if self.created_test_files:
            print(f"\nCreated test files:")
            for test_file in self.created_test_files:
                print(f"  - {test_file}")
        
        print(f"{'='*50}")
    
    def save_report(self, output_file: str = 'public_methods_report.txt'):
        """Save detailed report to file."""
        stats = self.get_statistics()
        
        try:
            with open(output_file, 'w', encoding='utf-8') as f:
                f.write('=' * 60 + '\n')
                f.write('PUBLIC METHODS ANALYSIS REPORT\n')
                f.write('=' * 60 + '\n\n')
                
                f.write('SUMMARY:\n')
                f.write('-' * 40 + '\n')
                f.write(f"Execution time: {stats['duration_ms']:.2f} ms\n")
                f.write(f"Files scanned: {stats['total_files']}\n")
                f.write(f"Files with public methods: {stats['files_with_methods']}\n")
                f.write(f"Total public methods: {stats['total_methods']}\n")
                f.write(f"Test files created: {stats['created_test_files']}\n\n")
                
                if self.created_test_files:
                    f.write('CREATED TEST FILES:\n')
                    f.write('-' * 40 + '\n')
                    for test_file in self.created_test_files:
                        f.write(f"  • {test_file}\n")
                    f.write('\n')
                
                f.write('DETAILED ANALYSIS:\n')
                f.write('-' * 40 + '\n')
                
                for file, methods in self.all_methods.items():
                    f.write(f"\nFile: {file} ({len(methods)} methods)\n")
                    for method in methods:
                        f.write(f"  • public {method}()\n")
                
                f.write('\n' + '=' * 60 + '\n')
            
            logger.info(f"Report saved to {output_file}")
            
        except Exception as e:
            logger.error(f"Failed to save report: {e}")


def main():
    """Main execution function."""
    try:
        # Initialize analyzer
        analyzer = PublicMethodsAnalyzer(src_dir='app')
        
        # Perform analysis
        analyzer.analyze()
        
        # Create test files
        analyzer.create_test_files()
        
        # Generate reports
        analyzer.print_report()
        analyzer.save_report()
        
        return 0
        
    except KeyboardInterrupt:
        logger.info("Analysis interrupted by user")
        return 1
    except Exception as e:
        logger.error(f"Unexpected error: {e}")
        return 1


if __name__ == "__main__":
    exit(main())