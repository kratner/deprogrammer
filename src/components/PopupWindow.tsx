import React, { useState } from 'react';

interface PopupWindowProps {
  triggerElement: React.ReactNode;
  content: React.ReactNode;
  width?: string;
  height?: string;
  background?: string;
  padding?: string;
}

const PopupWindow: React.FC<PopupWindowProps> = ({
  triggerElement,
  content,
  width = '75%',
  height = '75%',
  background = 'rgba(0, 0, 0, 0.8)',
  padding = '1em'
}) => {
  const [isOpen, setIsOpen] = useState(false);

  const openPopup = () => {
    setIsOpen(true);
  };

  const closePopup = () => {
    setIsOpen(false);
  };

  return (
    <div>
      <div onClick={openPopup}>{triggerElement}</div>
      {isOpen && (
        <div
          className='popup-window'
          style={{
            position: 'fixed',
            top: '50%',
            left: '50%',
            transform: 'translate(-50%, -50%)',
            width,
            height,
            background: background,
            borderRadius: '1em',
            boxShadow: '0 2px 8px rgba(0, 0, 0, 0.15)',
            overflow: 'auto',
            zIndex: 9999,
            padding: padding
          }}
        >
          {content}
          <button onClick={closePopup}>Close</button>
        </div>
      )}
    </div>
  );
};

export default PopupWindow;
