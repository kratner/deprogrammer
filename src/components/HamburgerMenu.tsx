import React, { useState } from 'react';

interface MenuItem {
  label: string;
  onClick: () => void;
}

interface HamburgerMenuProps {
  menuItems: MenuItem[];
  position?: 'left' | 'right';
}

const HamburgerMenu: React.FC<HamburgerMenuProps> = ({ menuItems, position = 'left' }) => {
  const [isOpen, setIsOpen] = useState(false);

  const toggleMenu = () => {
    setIsOpen((prevIsOpen) => !prevIsOpen);
  };

  const closeMenu = () => {
    setIsOpen(false);
  };

  const menuPosition = position === 'right' ? 'right' : 'left';

  return (
    <div className={`hamburger-menu-container ${menuPosition}`}>
      <button className={`hamburger-menu-button ${isOpen ? 'open' : ''}`} onClick={toggleMenu}>
        <div className="hamburger-menu-icon"></div>
        <div className="hamburger-menu-icon"></div>
        <div className="hamburger-menu-icon"></div>
      </button>
      {isOpen && (
        <ul className={`hamburger-menu ${menuPosition}`}>
          {menuItems.map((item, index) => (
            <li key={index} onClick={() => item.onClick()}>
              {item.label}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
};

export default HamburgerMenu;
